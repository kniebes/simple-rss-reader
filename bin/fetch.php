<?php

declare(strict_types=1);

use Kniebes\SimpleRssReader\Feed\FeedParser;
use Kniebes\SimpleRssReader\Feed\MultiFeedFetcher;
use Kniebes\SimpleRssReader\Opml\OpmlReader;
use Kniebes\SimpleRssReader\Storage\Database;
use Kniebes\SimpleRssReader\Storage\PostRepository;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$projectRoot = dirname(__DIR__);
$opmlPath = $projectRoot . '/var/feeds.opml';

$envFile = $projectRoot . '/.env';
if (is_file($envFile)) {
    (new Dotenv())->loadEnv($envFile);
}

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
        fwrite(STDERR, "{$prefix} [FAIL] {$url}: {$error}\n");
        continue;
    }

    try {
        $entries = $feedParser->parse($body);
    } catch (Throwable $e) {
        fwrite(STDERR, "{$prefix} [FAIL] {$url}: {$e->getMessage()}\n");
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

    echo "{$prefix} [OK] {$url} ({$newCount} new)\n";
}

printf('Total New Items: %s' . PHP_EOL, $totalCount);

$deleted = $repository->deleteOlderThanDays($retentionDays);
printf('Deleted %d posts older than %d days.' . PHP_EOL, $deleted, $retentionDays);
