# simple-rss-reader

Minimaler PHP-RSS-Reader. Lädt Feeds aus einer OPML-Datei, speichert Entries in
MariaDB (DDEV-Container), klassifiziert sie optional per Claude in Kategorien
und zeigt sie über eine einzelne `index.php` an.

## Voraussetzungen

- DDEV (PHP 8.4, nginx-fpm, MariaDB 11.8)
- Composer (über `ddev composer`)
- `ext-curl` (für parallele Feed-Fetches und die Anthropic-API)
- `pdo_mysql` (Standard im DDEV-PHP-Image)

## Setup

```sh
ddev start
ddev composer install
```

Feeds werden aus `var/feeds.opml` gelesen. OPML aus deinem Feed-Reader (z. B.
NetNewsWire) exportieren und dort ablegen.

Kategorien werden aus `var/categories.md` gelesen (Format pro Zeile:
`- Name: Beschreibung`). Die Beschreibung geht 1:1 in den System-Prompt des
Classifiers und entscheidet, was in eine Kategorie fällt.

In `.env` (gitignored) sind zwei Variablen erforderlich:

```
ANTHROPIC_API_KEY=sk-ant-...
DATABASE_URL="mysql://db:db@db:3306/db"
```

`DATABASE_URL` zeigt per Default auf den DDEV-MariaDB-Container (Host `db`,
User/Pass/Datenbank jeweils `db`). Anpassen nur, wenn du gegen eine andere
MySQL-/MariaDB-Instanz fahren willst. `.env.example` enthält die Templates.

Geladen wird via `symfony/dotenv` (`Dotenv::loadEnv()`); `.env.local`
überschreibt `.env` (sinnvoll für echte Secrets, während `.env` als
Template-Platzhalter dient), und im Shell gesetzte ENV-Variablen schlagen
beides.

## Verwendung

**Feeds laden / aktualisieren** (CLI, idempotent):

```sh
ddev exec php bin/fetch.php
```

Lädt alle Feeds parallel (curl_multi, bis zu 10 gleichzeitig), schreibt neue
Entries in die DB-Tabelle `posts` (muss vorher manuell angelegt werden, siehe
[DB-Schema](#db-schema)) und löscht am Ende Posts, die älter als 5 Tage sind.
Dedup läuft über `guid` (RSS `<guid>` bzw. Atom `<id>`, Fallback `<link>`);
bei Bestandsposts wird ein noch leerer `content` einmalig nachgefüllt, sonst
bleibt der Status unangetastet.

**Posts klassifizieren** (CLI, idempotent):

```sh
ddev exec php bin/categorize.php
```

Liest alle noch unkategorisierten `new`-Posts in Batches à 25 und ordnet sie
über Claude Haiku 4.5 einer der Kategorien aus `var/categories.md` zu. Posts
ohne klaren Match landen mit leerem String in der DB (kein erneuter Versuch)
und werden in der UI unter „Nicht kategorisiert" gesammelt.

**Anzeige**: `https://simple-rss-reader.ddev.site/`

- `?filter=new` (Default) — ungelesene Posts
- `?filter=read` — gelesene Posts
- `?filter=all` — alle Posts
- Sektionen kommen aus den DB-vorhandenen Kategorien. `categories.md` dient
  nur als Sortier-Hint: bekannte Kategorien erscheinen in der dort
  definierten Reihenfolge, abweichende DB-Kategorien danach alphabetisch.
  „Nicht kategorisiert" (`category` ist `NULL` oder `''`) steht am Ende.
- Button „Alle als gelesen markieren" setzt jeden `new`-Post auf `read`.

## Struktur

```
bin/
  fetch.php                  # CLI: OPML → Feeds parallel laden → DB schreiben → Retention
  categorize.php             # CLI: ungelabelte Posts an Anthropic API → category setzen
public/index.php             # Web: Liste + Filter + Kategorie-Gruppierung + Mark-all-read
src/
  Feed/Feed.php              # Value Object (feedUrl, blogUrl)
  Feed/Entry.php             # Value Object (date, permalink, title, content)
  Feed/FeedParser.php        # RSS 2.0 + Atom 1.0 via SimpleXML
  Feed/MultiFeedFetcher.php  # curl_multi, parallel mit Timeout + Redirects
  Opml/OpmlReader.php        # rekursiver OPML-Reader
  Category/Category.php      # Value Object (name, description, relevance)
  Category/CategoryList.php  # Parser für var/categories.md
  Category/Classifier.php    # Anthropic Messages API (Haiku 4.5) + Prompt-Caching
  Storage/Database.php       # PDO/MySQL-Verbindung aus DATABASE_URL (parsed URL → DSN)
  Storage/PostRepository.php # UPSERT, findByStatus, findGroupedByCategory, markAllRead, …
  Util/Text.php              # HTML → Plain-Text Excerpt
var/
  feeds.opml                 # Input
  categories.md              # Input (nur für den Classifier)
```

## DB-Schema

```sql
CREATE TABLE posts (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    date      DATETIME      NOT NULL,                  -- intern UTC, Anzeige in Europe/Berlin
    feed_url  VARCHAR(2048) NOT NULL,
    blog_url  VARCHAR(2048) NOT NULL,
    guid      VARCHAR(255)  NOT NULL UNIQUE,           -- Dedup-Key: RSS <guid> / Atom <id>,
                                                       -- Fallback <link> wenn keiner gesetzt
    permalink VARCHAR(2048) NULL,                      -- URL zum Anzeigen — NULL wenn Feed
                                                       -- keinen Link liefert (z. B. rss-club)
    title     TEXT          NOT NULL,
    content   MEDIUMTEXT    NOT NULL,
    status    ENUM('new','read') NOT NULL DEFAULT 'new',
    category  VARCHAR(64)   NULL,                      -- NULL = noch nicht klassifiziert,
                                                       -- '' = klassifiziert ohne Match
    INDEX idx_posts_status_date (status, date),
    INDEX idx_posts_category    (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Schema wird manuell angelegt — kein Auto-Setup, kein Migrations-Tool. Beim
ersten Setup (oder nach Schema-Änderungen) das `CREATE TABLE` direkt im
DDEV-Container ausführen:

```sh
ddev mysql -e "DROP TABLE IF EXISTS posts; CREATE TABLE posts (...);"
ddev exec php bin/fetch.php
```

Die 5-Tages-Retention macht ein gelegentliches `DROP` + Re-Fetch
verkraftbar.

## Caveats

- **Kaputte Feeds werden übersprungen.** Beispiel: `blogs.nabu.de/feed/`
  prefixt seinen RSS-Body mit einem MySQL-Fehlertext und ist damit kein gültiges
  XML mehr. Der Fetcher loggt `[FAIL] …` auf STDERR und macht weiter.
- **`(n new)`-Zähler ist nicht ganz exakt.** `PDO::rowCount()` unterscheidet
  bei `ON DUPLICATE KEY UPDATE` nicht trennscharf zwischen INSERT und
  qualifiziertem UPDATE — bei einem Backfill (z. B. `content` nachgezogen)
  zählen UPDATEs als „new". Im Steady State stimmt der Zähler wieder.
- **Retention ist hart auf 5 Tage.** `bin/fetch.php` löscht am Ende jedes Laufs
  alle Posts, deren `date` älter ist — Lesezustand inklusive. Wer länger
  archivieren will, muss den Konstanten-Wert in `fetch.php` anheben.
- **`categorize.php` bricht bei API-Fehlern hart ab** (`exit 2`), damit der
  nächste Lauf denselben Batch retried. Kein partielles Persistieren mitten im
  Batch.
- **Anzeigezeit ist hart auf `Europe/Berlin`.** `public/index.php` konvertiert
  die UTC-DATETIME aus der DB explizit dorthin. Wer in einer anderen TZ
  rendern will, muss das im Template ändern.
