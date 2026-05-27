<?php

declare(strict_types=1);

use Kniebes\SimpleRssReader\Feed\FeedParser;
use Kniebes\SimpleRssReader\Feed\MultiFeedFetcher;
use Kniebes\SimpleRssReader\Kernel;
use Kniebes\SimpleRssReader\Opml\OpmlReader;
use Kniebes\SimpleRssReader\Storage\Database;
use Kniebes\SimpleRssReader\Storage\PostRepository;

require __DIR__ . '/../vendor/autoload.php';

set_time_limit(0);

// Streaming-Setup: gzip/deflate aus, alle Puffer-Ebenen schließen, implicit flush an.
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', 'Off');
header('Content-Encoding: none');
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

// Mini-HTML-Preamble + Padding, damit Firefox/Safari den Render-Threshold (~1 KB) erreichen.
echo "<!doctype html><meta charset=utf-8><title>fetch</title>\n";
echo str_repeat(' ', 1024) . "\n";
flush();

// Apache mod_proxy_fcgi puffert FastCGI-Pakete ohne flushpackets=on;
// per-line padding drückt jede Zeile über die Puffer-Schwelle und erzwingt Auslieferung.
$tick = static function (string $line): void {
    echo $line . str_repeat(' ', 4096) . "\n";
};

$projectRoot = dirname(__DIR__);
$opmlPath = $projectRoot . '/var/feeds.opml';

Kernel::environment();

$opmlReader = new OpmlReader();
$feedParser = new FeedParser();
$fetcher = new MultiFeedFetcher();

try {
    $repository = new PostRepository(Database::open());
    $feeds = $opmlReader->readFeeds($opmlPath);
} catch (Throwable $e) {
    $tick('[FATAL] init: ' . $e->getMessage() . '<br>');
    echo '<a href="/">Home</a>';
    exit;
}

$total = count($feeds);
$byUrl = [];
foreach ($feeds as $feed) {
    $byUrl[$feed->feedUrl] = $feed;
}

$retentionDays = 5;
$cutoff = new DateTimeImmutable(sprintf('-%d days', $retentionDays));

$done = 0;
$totalCount = 0;

foreach ($fetcher->fetchAll(array_keys($byUrl)) as $result) {
    $done++;
    $feed = $byUrl[$result->url];
    $prefix = sprintf('[%d/%d]', $done, $total);

    if ($result->body === null) {
        $tick($prefix . ' [FAIL] ' . $result->url . ': ' . $result->error . '<br>');
        continue;
    }

    try {
        $entries = $feedParser->parse($result->body);
    } catch (Throwable $e) {
        $tick($prefix . ' [FAIL] ' . $result->url . ': ' . $e->getMessage() . '<br>');
        continue;
    }

    $newCount = 0;
    $skipped = 0;
    foreach ($entries as $entry) {
        if ($entry->date < $cutoff) {
            continue;
        }
        try {
            if ($repository->insertIgnore($entry, $feed)) {
                $newCount++;
                $totalCount++;
            }
        } catch (Throwable $e) {
            $tick($prefix . ' [FAIL] ' . $result->url . ': ' . $e->getMessage() . '<br>');
            // Einzelnes Item überspringen statt den ganzen Lauf abzubrechen —
            // z. B. guid/permalink länger als die Spalte (strict mode wirft).
            $skipped++;
        }
    }

    $note = $skipped > 0
        ? sprintf(' (%d new, %d skipped)', $newCount, $skipped)
        : sprintf(' (%d new)', $newCount);
    $tick($prefix . ' [OK] ' . $result->url . $note . '<br>');
}

$tick(sprintf('Total New Items: %d<br>', $totalCount));

try {
    $deleted = $repository->deleteOlderThanDays($retentionDays);
    $tick(sprintf('Deleted %d posts older than %d days.<br>', $deleted, $retentionDays));
} catch (Throwable $e) {
    $tick('[WARN] cleanup failed: ' . $e->getMessage() . '<br>');
}

echo '<a href="/">Home</a> &mdash; <a href="/categorize.php">Klassifizieren</a>';