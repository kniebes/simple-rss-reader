<?php

declare(strict_types=1);

use Kniebes\SimpleRssReader\Feed\FeedParser;
use Kniebes\SimpleRssReader\Feed\FetchResult;
use Kniebes\SimpleRssReader\Feed\MultiFeedFetcher;
use Kniebes\SimpleRssReader\Kernel;
use Kniebes\SimpleRssReader\Opml\OpmlReader;
use Kniebes\SimpleRssReader\Storage\Database;
use Kniebes\SimpleRssReader\Storage\PostRepository;
use Kniebes\SimpleRssReader\Util\Streaming;

require __DIR__ . '/../vendor/autoload.php';

Streaming::begin(title: 'fetch');

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
    Streaming::tick('[FATAL] init: ' . $e->getMessage() . '<br>');
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

$fetcher->fetchAll(
    urls: array_values(array_keys($byUrl)),
    onResult: function (FetchResult $result) use (
        $byUrl,
        $feedParser,
        $repository,
        $cutoff,
        $total,
        &$done,
        &$totalCount,
    ): void {
        $done++;
        $totalCount += MultiFeedFetcher::processResult(
            result: $result,
            feed: $byUrl[$result->url],
            feedParser: $feedParser,
            repository: $repository,
            cutoff: $cutoff,
            prefix: sprintf('[%d/%d]', $done, $total),
        );
    },
);

Streaming::tick(sprintf('Total New Items: %d<br>', $totalCount));

try {
    $deleted = $repository->deleteOlderThanDays($retentionDays);
    Streaming::tick(sprintf('Deleted %d posts older than %d days.<br>', $deleted, $retentionDays));
} catch (Throwable $e) {
    Streaming::tick('[WARN] cleanup failed: ' . $e->getMessage() . '<br>');
}

echo '<a href="/">Home</a> &mdash; <a href="/categorize.php">Klassifizieren</a>';