<?php

declare(strict_types=1);

use Kniebes\SimpleRssReader\Kernel;
use Kniebes\SimpleRssReader\Opml\OpmlReader;
use Kniebes\SimpleRssReader\Storage\Database;
use Kniebes\SimpleRssReader\Storage\PostRepository;
use Kniebes\SimpleRssReader\Util\FullContentExtractor;
use Kniebes\SimpleRssReader\Util\Html;
use Kniebes\SimpleRssReader\Util\PostRenderer;

require __DIR__ . '/../vendor/autoload.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit;
}

Kernel::environment();

try {
    $repository = new PostRepository(Database::open());
    $post = $repository->findById(id: $id);
    if ($post === null) {
        http_response_code(404);
        exit;
    }
    $repository->markRead(id: $id);
} catch (Throwable $e) {
    http_response_code(500);
    exit;
}

$rawHtml = (string) ($post['full_content'] ?? '');
$notice = '';

if ($rawHtml === '') {
    $feedUrl = (string) ($post['feed_url'] ?? '');
    $permalink = (string) ($post['permalink'] ?? '');

    $isTruncatedFeed = false;
    if ($feedUrl !== '') {
        try {
            $feeds = (new OpmlReader())->readFeeds(opmlPath: dirname(__DIR__) . '/var/feeds.opml');
            foreach ($feeds as $feed) {
                if ($feed->feedUrl === $feedUrl) {
                    $isTruncatedFeed = $feed->truncated;
                    break;
                }
            }
        } catch (Throwable) {
            // OPML nicht lesbar -> Fallback auf Feed-Content
        }
    }

    if ($isTruncatedFeed && $permalink !== '') {
        try {
            $rawHtml = FullContentExtractor::extract(url: $permalink);
            try {
                $repository->saveFullContent(id: $id, html: $rawHtml);
            } catch (Throwable) {
                // Persistieren fehlgeschlagen ist nicht render-relevant
            }
        } catch (Throwable $e) {
            $notice = '<p class="notice"><em>Volltext konnte nicht geladen werden: ' . Html::escape($e->getMessage()) . '</em></p>';
        }
    }

    if ($rawHtml === '') {
        $rawHtml = (string) ($post['content'] ?? '');
    }
}

$sanitized = $notice . Html::sanitize(html: $rawHtml);

header('Content-Type: text/html; charset=utf-8');
echo PostRenderer::renderExpanded(post: $post, sanitizedContent: $sanitized);
