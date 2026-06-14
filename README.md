# simple-rss-reader

Minimaler PHP-RSS-Reader. Lädt Feeds aus einer OPML-Datei, speichert Entries in
MariaDB (DDEV-Container), klassifiziert sie optional per Claude in Kategorien
und zeigt sie server-rendered an. UI mit [htmx](https://htmx.org) (self-hosted,
kein Node-Build), Templates in `templates/`, dünne Controller in `public/`,
Geschäftslogik unter `src/`.

Lokal erreichbar unter der DDEV-URL (Default: <https://simple-rss-reader.ddev.site/>).

## Voraussetzungen

- DDEV (PHP 8.4, apache-fpm, MariaDB 11.8)
- Composer (über `ddev composer`) — zieht u. a. `symfony/dotenv`,
  `ezyang/htmlpurifier` für die HTML-Sanitisierung und `j0k3r/graby` fürs
  Nachladen von Volltexten bei verstümmelten Feeds
- `ext-curl` (für parallele Feed-Fetches und die Anthropic-API)
- `ext-tidy` (von graby vorausgesetzt — in DDEV via `webimage_extra_packages`
  in `.ddev/config.yaml` aktiviert; auf Prod-Hosts üblicherweise schon dabei)
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

In `.env` (gitignored) liegen die Konfigurationswerte:

```
ANTHROPIC_API_KEY=sk-ant-...
DATABASE_URL="mysql://db:db@db:3306/db"
AUTH_PASSWORD_HASH=JDJ5JDEy...        # base64-kodierter bcrypt-Hash
AUTH_SECRET=b4ee8b84...               # Zufalls-Secret zum Signieren des Cookies
```

`AUTH_PASSWORD_HASH` und `AUTH_SECRET` steuern den eingebauten Cookie-Login
(siehe [Sicherheit](#sicherheit)) — ohne beide kommt niemand rein.

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
Entries in die DB-Tabelle `simple_rss_reader_posts` (muss vorher manuell
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
- Filter-Wechsel läuft per htmx — kein Full-Reload, URL wird über `hx-push-url`
  aktualisiert (Browser-Back funktioniert). Bei `HX-Request`-Header liefert
  `index.php` nur das Listen-Fragment statt der ganzen Seite.
- Sektionen kommen aus den DB-vorhandenen Kategorien. `categories.md` dient
  nur als Sortier-Hint: bekannte Kategorien erscheinen in der dort
  definierten Reihenfolge, abweichende DB-Kategorien danach alphabetisch.
  „Nicht kategorisiert" (`category` ist `NULL` oder `''`) steht am Ende.
- Klick auf eine Card öffnet die Vollansicht inline (expandiert den Article
  in place statt zur Permalink-Seite zu springen). Der vollständige
  `content:encoded` aus dem Feed wird beim Rendern durch HTMLPurifier
  sanitisiert (Whitelist in `Html::sanitize()`, Cache unter
  `var/cache/htmlpurifier/`). Der externe Permalink bleibt als ↗-Button in
  der Meta-Bar erhalten. Beim Öffnen wird der Post serverseitig auf `read`
  gesetzt.
- **Volltext-Nachladen bei verstümmelten Feeds.** Outlines im OPML können mit
  `truncated="true"` markiert werden:
  ```xml
  <outline … xmlUrl="https://www.tagesschau.de/index~rss2.xml" truncated="true"/>
  ```
  Beim ersten Öffnen so eines Posts holt `post.php` die Permalink-Seite über
  [graby](https://github.com/j0k3r/graby) (Readability + ~1500 site-spezifische
  Extraktionsregeln aus `j0k3r/graby-site-config`), persistiert das Ergebnis in
  `full_content` und rendert es. Folge-Klicks gehen direkt aus der DB. Während
  der ~0,3–1 s Roundtrip läuft am oberen Rand der Card ein animierter
  Loading-Strip (htmx setzt `.htmx-request` auf den Trigger, CSS macht den
  Rest). Schlägt die Extraktion fehl (Timeout, HTTP-Fehler, leeres Ergebnis),
  wird der Original-Feed-Content gerendert und oben eine kleine rote
  `.notice`-Box mit der Fehlermeldung eingeblendet.
- Der ☆/★-Button toggelt den Favoriten-Status via htmx (`/favorite.php` gibt
  das neue Button-Fragment zurück). Favoriten überleben die 5-Tage-Retention
  (`is_favorite = 1` ist vom Retention-DELETE ausgenommen).
- Der ↺-Button auf gelesenen Cards / in der Vollansicht markiert den Post per
  `/unread.php` wieder als `new` und rendert die Card frisch.
- Button „Alle als gelesen markieren" setzt jeden `new`-Post auf `read`
  (klassischer POST + Redirect, kein htmx).
- Nav-Link „Fetch" triggert `/fetch.php` direkt aus der UI.
- Nav-Link „Logout" löscht den Login-Cookie (`/logout.php`) und führt zurück
  zur Login-Seite.

## DB-Schema

```sql
CREATE TABLE simple_rss_reader_posts (
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
    full_content MEDIUMTEXT  NULL,                       -- nachgeladener Volltext für truncated-Feeds,
                                                          -- NULL solange noch nicht abgerufen
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
ddev mysql -e "DROP TABLE IF EXISTS simple_rss_reader_posts; CREATE TABLE simple_rss_reader_posts (...);"
ddev exec php public/fetch.php
```

Der Tabellen-Name (`simple_rss_reader_posts`) trägt ein Projekt-Präfix, weil
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

Die App bringt einen **eingebauten Cookie-Login** mit (Single-User). Jeder
web-erreichbare Endpoint ruft am Anfang `Auth::requireLogin()` auf
(`src/Util/Auth.php`) und leitet ohne gültigen Cookie auf `/login.php` um.
Das ist wichtig, weil besonders `categorize.php` die Anthropic-API aufruft und
damit **echte Kosten** verursacht (dein API-Guthaben) — und `fetch.php` in die
DB schreibt und alte Posts löscht.

**Wie es funktioniert:**

- Passwort wird als bcrypt-Hash geprüft (`password_verify`); der Hash liegt
  **base64-kodiert** in `AUTH_PASSWORD_HASH`. Base64 vermeidet, dass das `$` im
  bcrypt-Hash von `symfony/dotenv` als Variable expandiert wird — so ist das
  Quoting in der `.env` egal.
- Nach erfolgreichem Login setzt `login.php` einen signierten Cookie `srr_auth`
  (`<expiry>:<HMAC-SHA256>`, signiert mit `AUTH_SECRET`). Kein Server-State,
  keine Session, keine DB — die Gültigkeit steckt komplett in der Signatur.
- Cookie-Attribute: `Secure`, `HttpOnly`, `SameSite=Lax`, Lebensdauer 1 Jahr
  (per `AUTH_COOKIE_LIFETIME` änderbar). Bewusst ein server-gesetzter Cookie:
  iOS-Safari persistiert die zuverlässig (anders als Basic-Auth-Credentials,
  und nicht betroffen von ITPs 7-Tage-Cap, der nur per JS gesetzte Cookies
  trifft).
- **CLI-Aufrufe sind ausgenommen** (`PHP_SAPI === 'cli'`): `fetch.php` und
  `categorize.php` laufen per Cron / `ddev exec` ohne Login weiter, nur der
  Browser-Zugriff verlangt ihn.

**Einrichtung** — beide Werte in die `.env` eintragen:

```sh
# bcrypt-Hash (base64-kodiert) aus deinem Passwort erzeugen:
ddev exec php -r 'echo base64_encode(password_hash("DEINPASSWORT", PASSWORD_DEFAULT)), "\n";'
# Secret zum Signieren des Cookies:
ddev exec php -r 'echo bin2hex(random_bytes(32)), "\n";'
```

Sind `AUTH_PASSWORD_HASH` oder `AUTH_SECRET` leer, ist der Login **fail closed**
— es kommt niemand rein.

Auf Prod werden beide Werte in die dortige `.env` eingetragen (wird nicht mit
deployt). Eine separate HTTP-Basic-Auth (`.htaccess`) ist damit nicht mehr
nötig und sollte entfernt werden — sonst greifen beide Schichten gleichzeitig.

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
- **Anzeigezeit ist hart auf `Europe/Berlin`.** `PostRenderer::formatDatetime()`
  konvertiert die UTC-DATETIME aus der DB explizit dorthin. Wer in einer
  anderen TZ rendern will, muss das in der Methode ändern.
- **Browser-Output von `fetch.php`/`categorize.php` ist gestreamt.** HTML-Preamble
  + Padding-Zeilen (4 KB) drücken jede Statusmeldung über die FastCGI-/Browser-
  Puffer-Schwelle, damit Fortschritt live sichtbar ist statt erst am Ende. Apache
  `mod_proxy_fcgi` puffert sonst ohne `flushpackets=on`.
