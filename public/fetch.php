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
$repository = new PostRepository(Database::open());

$feeds = $opmlReader->readFeeds($opmlPath);
$total = count($feeds);
$byUrl = [];
foreach ($feeds as $feed) {
    $byUrl[$feed->feedUrl] = $feed;
}

$retentionDays = 5;
$cutoff = new DateTimeImmutable("-{$retentionDays} days");

$done = 0;
$totalCount = 0;

foreach ($fetcher->fetchAll(array_keys($byUrl)) as [$url, $body, $error]) {
    $done++;
    $feed = $byUrl[$url];
    $prefix = sprintf('[%d/%d]', $done, $total);

    if ($body === null) {
        $tick("{$prefix} [FAIL] {$url}: {$error}<br>");
        continue;
    }

    try {
        $entries = $feedParser->parse($body);
    } catch (Throwable $e) {
        $tick("{$prefix} [FAIL] {$url}: {$e->getMessage()}<br>");
        continue;
    }

    $newCount = 0;
    foreach ($entries as $entry) {
        if ($entry->date < $cutoff) {
            continue;
        }
        if ($repository->insertIgnore($entry, $feed)) {
            $newCount++;
            $totalCount++;
        }
    }

    $tick("{$prefix} [OK] {$url} ({$newCount} new)<br>");
}

$tick(sprintf('Total New Items: %d<br>', $totalCount));

$deleted = $repository->deleteOlderThanDays($retentionDays);
$tick(sprintf('Deleted %d posts older than %d days.<br>', $deleted, $retentionDays));
echo '<a href="/">Home</a> &mdash; <a href="/categorize.php">Klassifizieren</a>';