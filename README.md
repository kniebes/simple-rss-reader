# simple-rss-reader

Minimaler PHP-RSS-Reader. Lädt Feeds aus einer OPML-Datei, speichert Entries in
SQLite und zeigt sie über eine einzelne `index.php` an.

## Voraussetzungen

- DDEV (PHP 8.4, nginx-fpm)
- Composer (über `ddev composer`)

## Setup

```sh
ddev start
ddev composer install
```

Feeds werden aus `var/feeds.opml` gelesen. OPML aus deinem Feed-Reader (z. B.
NetNewsWire) exportieren und dort ablegen.

## Verwendung

**Feeds laden / aktualisieren** (CLI, idempotent):

```sh
ddev exec php bin/fetch.php
```

Schreibt neue Entries in `var/posts.db` (wird beim ersten Lauf angelegt).
Duplikate via `permalink` werden ignoriert. Bei Bestandsposts wird ein noch
leerer `content` einmalig nachgefüllt; sonst bleibt der Status unangetastet.

**Anzeige**: `https://simple-rss-reader.ddev.site/`

- `?filter=new` (Default) — ungelesene Posts
- `?filter=read` — gelesene Posts
- `?filter=all` — alle Posts
- Button „Alle als gelesen markieren" setzt jeden `new`-Post auf `read`.

## Struktur

```
bin/fetch.php          # CLI: OPML → Feeds laden → DB schreiben
public/index.php       # Web: Liste + Filter + Mark-all-read
src/
  Feed/Feed.php        # Value Object (feedUrl, blogUrl)
  Feed/Entry.php       # Value Object (date, permalink, title, content)
  Feed/FeedParser.php  # RSS 2.0 + Atom 1.0 via SimpleXML
  Opml/OpmlReader.php  # rekursiver OPML-Reader
  Storage/Database.php       # PDO/SQLite + Schema (idempotent)
  Storage/PostRepository.php # UPSERT, findByStatus, markAllRead
var/
  feeds.opml           # Input
  posts.db             # SQLite-DB (gitignored, entsteht beim Fetch)
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
              CHECK (status IN ('new','read'))
);
CREATE INDEX idx_posts_status_date ON posts(status, date DESC);
```

## Caveats

- **Kaputte Feeds werden übersprungen.** Beispiel: `nrw.nabu.de/rssfeed.php`
  prefixt seinen RSS-Body mit einem MySQL-Fehlertext und ist damit kein gültiges
  XML mehr. Der Fetcher loggt `[FAIL] …` auf STDERR und macht weiter.
- **`(n new)`-Zähler ist nicht ganz exakt.** Bei einer Schema-Erweiterung
  (z. B. `content` nachgezogen) kann ein Lauf Bestandsposts updaten, die im
  Zähler dann als "new" auftauchen — ab dem nächsten Lauf wieder korrekt.
