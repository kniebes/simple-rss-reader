# simple-rss-reader

Minimaler PHP-RSS-Reader. Lädt Feeds aus einer OPML-Datei, speichert Entries in
MariaDB (DDEV-Container), klassifiziert sie optional per Claude in Kategorien
und zeigt sie über eine einzelne `index.php` an.

Lokal erreichbar unter der DDEV-URL (Default: <https://simple-rss-reader.ddev.site/>).

## Voraussetzungen

- DDEV (PHP 8.4, apache-fpm, MariaDB 11.8)
- Composer (über `ddev composer`)
- `ext-curl` (für parallele Feed-Fetches und die Anthropic-API)
- `pdo_mysql` (Standard im DDEV-PHP-Image)

## Setup

```sh
ddev start
ddev composer install
```

Die beiden Eingabedateien `var/feeds.opml` und `var/categories.md` sind
gitignored (persönliche Daten) — getrackt sind nur die Vorlagen. Zum Start
kopieren:

```sh
cp var/feeds.opml.example var/feeds.opml
cp var/categories.md.example var/categories.md
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

Den `ANTHROPIC_API_KEY` gibt es **nicht** in den Einstellungen der Claude-Chat-App
(claude.ai) — die API ist ein eigenes, davon getrenntes Produkt mit eigener,
nutzungsbasierter Abrechnung (Guthaben/Credits), unabhängig von einem etwaigen
Claude-Pro-Abo. Den Key erstellst du in der **Anthropic Console**:

1. Auf <https://console.anthropic.com> einloggen (ggf. Account anlegen).
2. Unter *Billing* etwas Guthaben aufladen — ohne Credits liefert die API einen
   Fehler.
3. Unter *API keys* → *Create Key* einen Key erzeugen und in `.env` eintragen.
   Der Key wird nur einmal angezeigt.

Nur `public/categorize.php` braucht den Key (für die Klassifizierung via Claude).
Ohne Key funktioniert der Reader trotzdem — die Posts landen dann alle unter
„Nicht kategorisiert".

`DATABASE_URL` zeigt per Default auf den DDEV-MariaDB-Container (Host `db`,
User/Pass/Datenbank jeweils `db`). Anpassen nur, wenn du gegen eine andere
MySQL-/MariaDB-Instanz fahren willst. `.env.example` enthält die Templates.

Geladen wird via `symfony/dotenv` (`Dotenv::loadEnv()`); `.env.local`
überschreibt `.env` (sinnvoll für echte Secrets, während `.env` als
Template-Platzhalter dient), und im Shell gesetzte ENV-Variablen schlagen
beides.

## Verwendung

**Feeds laden / aktualisieren** (idempotent — CLI oder Browser):

```sh
ddev exec php public/fetch.php
```

Oder im Browser: `https://simple-rss-reader.ddev.site/fetch.php` (auch
verlinkt als „Fetch" in der Navigation).

Lädt alle Feeds parallel (curl_multi, bis zu 10 gleichzeitig), schreibt neue
Entries in die DB-Tabelle `simeple_rss_reader_posts` (muss vorher manuell
angelegt werden, siehe [DB-Schema](#db-schema)) und löscht am Ende Posts, die
älter als 5 Tage sind. Dedup läuft über `guid` (RSS `<guid>` bzw. Atom `<id>`,
Fallback `<link>`); bei Bestandsposts wird ein noch leerer `content` einmalig
nachgefüllt, sonst bleibt der Status unangetastet.

**Posts klassifizieren** (idempotent — CLI oder Browser):

```sh
ddev exec php public/categorize.php
```

Oder im Browser: `https://simple-rss-reader.ddev.site/categorize.php`.

Liest alle noch unkategorisierten `new`-Posts in Batches à 25 und ordnet sie
über Claude Haiku 4.5 einer der Kategorien aus `var/categories.md` zu. Posts
ohne klaren Match landen mit leerem String in der DB (kein erneuter Versuch)
und werden in der UI unter „Nicht kategorisiert" gesammelt.

**Anzeige**: `https://simple-rss-reader.ddev.site/`

- `?filter=new` (Default) — ungelesene Posts
- `?filter=read` — gelesene Posts
- `?filter=all` — alle Posts
- `?filter=favorite` — als Favorit markierte Posts (status-unabhängig)
- Sektionen kommen aus den DB-vorhandenen Kategorien. `categories.md` dient
  nur als Sortier-Hint: bekannte Kategorien erscheinen in der dort
  definierten Reihenfolge, abweichende DB-Kategorien danach alphabetisch.
  „Nicht kategorisiert" (`category` ist `NULL` oder `''`) steht am Ende.
- Button „Alle als gelesen markieren" setzt jeden `new`-Post auf `read`.
- Nav-Link „Fetch" triggert `/fetch.php` direkt aus der UI.
- Der ☆/★-Button an jedem Post toggelt den Favoriten-Status per Fetch-Request
  an `/favorite.php` (JS in `public/assets/js/site.js`). Favoriten überleben die
  5-Tage-Retention (`is_favorite = 1` ist vom Retention-DELETE ausgenommen).

## Struktur

```
public/
  index.php                  # Web: Liste + Filter (inkl. Favoriten) + Kategorie-Gruppierung + Mark-all-read
  fetch.php                  # OPML → Feeds parallel laden → DB schreiben → Retention
  categorize.php             # ungelabelte Posts an Anthropic API → category setzen
  favorite.php               # POST-Endpoint: is_favorite togglen (von site.js aufgerufen)
  assets/js/site.js          # Favoriten-Toggle (Fetch an favorite.php)
src/
  Kernel.php                 # .env laden (Dotenv) + Cache-Busting-Version für Assets
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
  feeds.opml.example         # Vorlage → nach feeds.opml kopieren (gitignored)
  categories.md.example      # Vorlage → nach categories.md kopieren (gitignored)
deployment.php.dist          # Vorlage → nach deployment.php kopieren (gitignored)
```

## DB-Schema

```sql
CREATE TABLE simeple_rss_reader_posts (
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
    is_favorite TINYINT(1)  NOT NULL DEFAULT 0,        -- Lesezeichen; überlebt die 5-Tage-Retention
    INDEX idx_posts_status_date (status, date),
    INDEX idx_posts_category    (category),
    INDEX idx_posts_favorite    (is_favorite)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Schema wird manuell angelegt — kein Auto-Setup, kein Migrations-Tool. Beim
ersten Setup (oder nach Schema-Änderungen) das `CREATE TABLE` direkt im
DDEV-Container ausführen:

```sh
ddev mysql -e "DROP TABLE IF EXISTS simeple_rss_reader_posts; CREATE TABLE simeple_rss_reader_posts (...);"
ddev exec php public/fetch.php
```

Der Tabellen-Name (`simeple_rss_reader_posts`) trägt ein Projekt-Präfix, weil
dieselbe DB auf dem Shared-Host produktiv mit anderen Projekten geteilt wird.

Die 5-Tages-Retention macht ein gelegentliches `DROP` + Re-Fetch
verkraftbar.

## Deployment

Falls der Reader über einen Shared Host läuft, kann das Projekt über
[`dg/ftp-deployment`](https://github.com/dg/ftp-deployment) deployed werden. 
Die Konfiguration liegt in `deployment.php`. Als Vorlage dient `deployment.php.dist`. 
Einfach kopieren und die FTPS-Zugangsdaten eintragen:  

```sh
cp deployment.php.dist deployment.php
```

`temp/` ist der Arbeits-Cache des Deployers, `var/deployment.log` das Lauf-Log.

```sh
vendor/bin/deployment deployment.php
```

## Sicherheit

**Die App bringt keine eigene Authentifizierung mit — alle Endpoints sind
offen.** Bei jeder öffentlich erreichbaren Installation gehört davor ein
Zugriffsschutz, z. B. HTTP Basic Auth. Sonst kann jeder Besucher die
Endpoints triggern, und besonders `categorize.php` ruft die Anthropic-API auf
und verursacht damit **echte Kosten** (dein API-Guthaben) — ein offener
`categorize.php`-Endpoint ist effektiv ein Geld-Leck. Auch `fetch.php`
schreibt in die DB und löscht alte Posts.

Auf Apache reicht eine `.htaccess` im Document-Root (`public/`):

```apacheconf
AuthType Basic
AuthName "Restricted"
AuthUserFile /absoluter/pfad/zu/.htpasswd
Require valid-user
```

Die `.htpasswd` daneben anlegen (außerhalb des Document-Roots ablegen, wenn
möglich):

```sh
htpasswd -c /absoluter/pfad/zu/.htpasswd deinbenutzer
```

## Caveats

- **Kaputte Feeds werden übersprungen.** Beispiel: `blogs.nabu.de/feed/`
  prefixt seinen RSS-Body mit einem MySQL-Fehlertext und ist damit kein gültiges
  XML mehr. Der Fetcher loggt `[FAIL] …` und macht weiter.
- **`(n new)`-Zähler ist nicht ganz exakt.** `PDO::rowCount()` unterscheidet
  bei `ON DUPLICATE KEY UPDATE` nicht trennscharf zwischen INSERT und
  qualifiziertem UPDATE — bei einem Backfill (z. B. `content` nachgezogen)
  zählen UPDATEs als „new". Im Steady State stimmt der Zähler wieder.
- **Retention ist hart auf 5 Tage.** `public/fetch.php` löscht am Ende jedes Laufs
  alle Posts, deren `date` älter ist — Lesezustand inklusive. Wer länger
  archivieren will, muss den Konstanten-Wert in `fetch.php` anheben.
- **`categorize.php` bricht bei API-Fehlern hart ab** (`exit 2`), damit der
  nächste Lauf denselben Batch retried. Kein partielles Persistieren mitten im
  Batch.
- **Anzeigezeit ist hart auf `Europe/Berlin`.** `public/index.php` konvertiert
  die UTC-DATETIME aus der DB explizit dorthin. Wer in einer anderen TZ
  rendern will, muss das im Template ändern.
- **Browser-Output von `fetch.php`/`categorize.php` ist gestreamt.** HTML-Preamble
  + Padding-Zeilen (4 KB) drücken jede Statusmeldung über die FastCGI-/Browser-
  Puffer-Schwelle, damit Fortschritt live sichtbar ist statt erst am Ende. Apache
  `mod_proxy_fcgi` puffert sonst ohne `flushpackets=on`.
