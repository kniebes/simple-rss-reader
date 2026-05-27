<?php

declare(strict_types=1);

use Kniebes\SimpleRssReader\Category\CategoryList;
use Kniebes\SimpleRssReader\Kernel;
use Kniebes\SimpleRssReader\Storage\Database;
use Kniebes\SimpleRssReader\Storage\PostRepository;
use Kniebes\SimpleRssReader\Util\Text;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$projectRoot = dirname(__DIR__);

Kernel::environment();

$renderErrorPage = static function (int $status, string $title, string $message): never {
    http_response_code($status);
    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><meta charset=utf-8><title>' . $safeTitle . '</title><h1>' . $safeTitle . '</h1><p>' . $safeMessage . '</p><p><a href="/">Zurück</a></p>';
    exit;
};

try {
    $repository = new PostRepository(Database::open());
} catch (Throwable $e) {
    $renderErrorPage(503, 'Reader nicht verfügbar', $e->getMessage());
}
$categories = CategoryList::fromFile($projectRoot . '/var/categories.md');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    try {
        $repository->markAllRead();
    } catch (Throwable $e) {
        $renderErrorPage(500, 'Markieren fehlgeschlagen', $e->getMessage());
    }
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/'), true, 303);
    exit;
}

$filter = $_GET['filter'] ?? 'new';
$onlyFavorites = $filter === 'favorite';
$status = match ($filter) {
    'favorite' => null,
    'read'     => 'read',
    'all'      => null,
    default    => 'new',
};
$filter = match (true) {
    $onlyFavorites     => 'favorite',
    $status === 'read' => 'read',
    $status === null   => 'all',
    default            => 'new',
};

try {
    $grouped = $repository->findGroupedByCategory($status, $onlyFavorites);
} catch (Throwable $e) {
    $renderErrorPage(503, 'Reader nicht verfügbar', $e->getMessage());
}
$totalCount = array_sum(array_map('count', $grouped));

function escape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function isSafeUrl(string $url): bool {
    // Nur absolute http(s)- oder protokoll-relative URLs als Link rendern —
    // verhindert javascript:/data:-Permalinks aus fremden Feeds.
    return preg_match('#^(https?://|//)#i', $url) === 1;
}

?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Reader</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="/assets/css/site.css?<?= Kernel::getFileVersion() ?>">
    <script defer src="/assets/js/site.js?<?= Kernel::getFileVersion() ?>"></script>
</head>
<body>
    <header>
        <h1>Simple Feed Reader</h1>
        <nav>
            <a class="button<?= $filter === 'new' ? ' active' : '' ?>" href="?filter=new">Neu</a>
            <a class="button<?= $filter === 'read' ? ' active' : '' ?>" href="?filter=read">Gelesen</a>
            <a class="button<?= $filter === 'all' ? ' active' : '' ?>" href="?filter=all">Alle</a>
            <a class="button<?= $filter === 'favorite' ? ' active' : '' ?>" href="?filter=favorite">★ Favoriten</a>
        </nav>
        <form method="post">
            <a class="button" href="/fetch.php">Fetch</a>
            <input type="hidden" name="action" value="mark_all_read">
            <button type="submit">Alle als gelesen markieren</button>
        </form>
    </header>

    <?php if ($totalCount === 0): ?>
        <p class="empty">Keine Posts.</p>
    <?php else: ?>
        <?php
        $relevance = array_flip($categories->names());
        $uncategorized = $grouped[''] ?? [];

        $sections = [];
        foreach ($grouped as $name => $posts) {
            if ($name === '') {
                continue;
            }
            $sections[] = [
                'title' => $name,
                'posts' => $posts,
                'sort'  => $relevance[$name] ?? PHP_INT_MAX,
            ];
        }
        usort($sections, static fn ($a, $b) => [$a['sort'], $a['title']] <=> [$b['sort'], $b['title']]);

        if ($uncategorized !== []) {
            $sections[] = ['title' => 'Nicht kategorisiert', 'posts' => $uncategorized];
        }
        ?>

        <section class="summary">
            <?php foreach ($sections as $section): ?>
                <a href="#<?= md5($section['title']) ?>"><?= escape($section['title']) ?> <span class="count">(<?= count($section['posts']) ?>)</span></a>
            <?php endforeach; ?>
        </section>

        <?php foreach ($sections as $section): ?>
            <section>
                <h2 id="<?= md5($section['title']) ?>"><?= escape($section['title']) ?> <span class="count">(<?= count($section['posts']) ?>)</span></h2>
                    <?php foreach ($section['posts'] as $post): ?>
                        <article>
                            <h3 class="<?= $post['status'] === 'new' ? 'new' : '' ?>">
                                <?php
                                $label = $post['title'] !== ''
                                    ? $post['title']
                                    : ($post['permalink'] ?? $post['blog_url']);
                                ?>
                                <?php if ($post['permalink'] !== null && isSafeUrl($post['permalink'])): ?>
                                    <a rel="noopener noreferrer" href="<?= escape($post['permalink']) ?>" target="_blank">
                                        <?= escape($label) ?>
                                    </a>
                                <?php else: ?>
                                    <?= escape($label) ?>
                                <?php endif; ?>
                            </h3>
                            <?php $exc = Text::excerpt($post['content'] ?? ''); ?>
                            <?php if ($exc !== ''): ?>
                                <div class="excerpt"><?= escape($exc) ?></div>
                            <?php endif; ?>
                            <div class="meta">
                                <?= escape((new DateTimeImmutable($post['date'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i')) ?>
                                ·
                                <a rel="noopener noreferrer" href="<?= escape($post['blog_url']) ?>" target="_blank">
                                    <?= escape(parse_url($post['blog_url'], PHP_URL_HOST) ?? $post['blog_url']) ?>
                                </a>
                                <?php $fav = !empty($post['is_favorite']); ?>
                                <button type="button"
                                        class="favorite-toggle<?= $fav ? ' is-favorite' : '' ?>"
                                        data-post-id="<?= (int) $post['id'] ?>"
                                        aria-pressed="<?= $fav ? 'true' : 'false' ?>"
                                        aria-label="Favorit"
                                        title="Favorit"><?= $fav ? '★' : '☆' ?></button>
                            </div>
                        </article>
                    <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
