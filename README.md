# simple-rss-reader

Minimaler PHP-RSS-Reader. Lädt Feeds aus einer OPML-Datei, speichert Entries in
SQLite, klassifiziert sie optional per Claude in Kategorien und zeigt sie über
eine einzelne `index.php` an.

## Voraussetzungen

- DDEV (PHP 8.4, nginx-fpm)
- Composer (über `ddev composer`)
- `ext-curl` (für parallele Feed-Fetches und die Anthropic-API)

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

Für die Klassifizierung ein Anthropic-API-Key in `.env` ablegen:

```
ANTHROPIC_API_KEY=sk-ant-...
```

## Verwendung

**Feeds laden / aktualisieren** (CLI, idempotent):

```sh
ddev exec php bin/fetch.php
```

Lädt alle Feeds parallel (curl_multi, bis zu 10 gleichzeitig), schreibt neue
Entries in `var/posts.db` (wird beim ersten Lauf angelegt) und löscht am Ende
Posts, die älter als 5 Tage sind. Duplikate via `permalink` werden ignoriert;
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
- Posts werden nach Kategorie gruppiert (Reihenfolge wie in `categories.md`),
  Unbekanntes / Nicht-Klassifiziertes sammelt sich am Ende.
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
  Storage/Database.php       # PDO/SQLite + Schema (idempotent)
  Storage/PostRepository.php # UPSERT, findByStatus, findGroupedByCategory, markAllRead, …
  Util/Text.php              # HTML → Plain-Text Excerpt
var/
  feeds.opml                 # Input
  categories.md              # Input
  posts.db                   # SQLite-DB (gitignored, entsteht beim Fetch)
```

## DB-Schema

```sql
CREATE TABLE posts (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    date      TEXT NOT NULL,                          -- ISO 8601
    feed_url  TEXT NOT NULL,
    blog_url  TEXT NOT NULL,
    permalink TEXT NOT NULL UNIQUE,
    title     TEXT NOT NULL,
    content   TEXT NOT NULL DEFAULT '',
    status    TEXT NOT NULL DEFAULT 'new'
              CHECK (status IN ('new','read')),
    category  TEXT NULL                                -- NULL = noch nicht klassifiziert,
                                                       -- '' = klassifiziert ohne Match
);
CREATE INDEX idx_posts_status_date ON posts(status, date DESC);
CREATE INDEX idx_posts_category   ON posts(category);
```

## Caveats

- **Kaputte Feeds werden übersprungen.** Beispiel: `nrw.nabu.de/rssfeed.php`
  prefixt seinen RSS-Body mit einem MySQL-Fehlertext und ist damit kein gültiges
  XML mehr. Der Fetcher loggt `[FAIL] …` auf STDERR und macht weiter.
- **`(n new)`-Zähler ist nicht ganz exakt.** Bei einer Schema-Erweiterung
  (z. B. `content` nachgezogen) kann ein Lauf Bestandsposts updaten, die im
  Zähler dann als "new" auftauchen — ab dem nächsten Lauf wieder korrekt.
- **Retention ist hart auf 5 Tage.** `bin/fetch.php` löscht am Ende jedes Laufs
  alle Posts, deren `date` älter ist — Lesezustand inklusive. Wer länger
  archivieren will, muss den Konstanten-Wert in `fetch.php` anheben.
- **`categorize.php` bricht bei API-Fehlern hart ab** (`exit 2`), damit der
  nächste Lauf denselben Batch retried. Kein partielles Persistieren mitten im
  Batch.
