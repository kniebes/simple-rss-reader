<?php

declare(strict_types=1);

use Kniebes\SimpleRssReader\Feed\FeedParser;
use Kniebes\SimpleRssReader\Opml\OpmlReader;
use Kniebes\SimpleRssReader\Storage\Database;
use Kniebes\SimpleRssReader\Storage\PostRepository;

require __DIR__ . '/../vendor/autoload.php';

$projectRoot = dirname(__DIR__);
$opmlPath = $projectRoot . '/var/feeds.opml';
$dbPath = $projectRoot . '/var/posts.db';

$opmlReader = new OpmlReader();
$feedParser = new FeedParser();
$repository = new PostRepository(Database::open($dbPath));

$context = stream_context_create([
    'http' => [
        'timeout' => 10,
        'header' => "User-Agent: simple-rss-reader/0.1\r\n",
        'follow_location' => 1,
    ],
    'https' => [
        'timeout' => 10,
        'header' => "User-Agent: simple-rss-reader/0.1\r\n",
        'follow_location' => 1,
    ],
]);

foreach ($opmlReader->readFeeds($opmlPath) as $feed) {
    $xml = @file_get_contents($feed->feedUrl, false, $context);
    if ($xml === false) {
        fwrite(STDERR, "[FAIL] {$feed->feedUrl}: download failed\n");
        continue;
    }

    try {
        $entries = $feedParser->parse($xml);
    } catch (Throwable $e) {
        fwrite(STDERR, "[FAIL] {$feed->feedUrl}: {$e->getMessage()}\n");
        continue;
    }

    $newCount = 0;
    foreach ($entries as $entry) {
        if ($repository->insertIgnore($entry, $feed)) {
            $newCount++;
        }
    }

    echo "[OK] {$feed->feedUrl} ({$newCount} new)\n";
}
