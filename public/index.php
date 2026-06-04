<?php

declare(strict_types=1);

use Kniebes\SimpleRssReader\Category\CategoryList;
use Kniebes\SimpleRssReader\Kernel;
use Kniebes\SimpleRssReader\Storage\Database;
use Kniebes\SimpleRssReader\Storage\PostRepository;
use Kniebes\SimpleRssReader\Util\Html;
use Kniebes\SimpleRssReader\Util\PostRenderer;

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

$isHtmxRequest = ($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true';

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
    $sections[] = ['title' => 'Nicht kategorisiert', 'posts' => $uncategorized, 'sort' => PHP_INT_MAX];
}

$renderPostList = static function () use ($sections, $totalCount): void {
    echo '<div id="post-list">';
    if ($totalCount === 0) {
        echo '<p class="empty">Keine Posts.</p>';
        echo '</div>';
        return;
    }
    ?>
        <section class="summary">
            <?php foreach ($sections as $section): ?>
                <a href="#<?= md5($section['title']) ?>"><?= Html::escape($section['title']) ?> <span class="count">(<?= count($section['posts']) ?>)</span></a>
            <?php endforeach; ?>
        </section>

        <?php foreach ($sections as $section): ?>
            <section>
                <h2 id="<?= md5($section['title']) ?>"><?= Html::escape($section['title']) ?> <span class="count">(<?= count($section['posts']) ?>)</span></h2>
                <?php foreach ($section['posts'] as $post): ?>
                    <?= PostRenderer::renderCard($post) ?>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    <?php
    echo '</div>';
};

if ($isHtmxRequest) {
    header('Content-Type: text/html; charset=utf-8');
    $renderPostList();
    exit;
}

?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Reader</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="/assets/css/site.css?<?= Kernel::getFileVersion() ?>">
    <script defer src="/assets/js/htmx.min.js?<?= Kernel::getFileVersion() ?>"></script>
    <script defer src="/assets/js/site.js?<?= Kernel::getFileVersion() ?>"></script>
</head>
<body>
    <header>
        <h1>Simple Feed Reader</h1>
        <nav>
            <?php
            $filters = [
                'new'      => 'Neu',
                'read'     => 'Gelesen',
                'all'      => 'Alle',
                'favorite' => '★ Favoriten',
            ];
            foreach ($filters as $filterValue => $label):
                $isActive = $filter === $filterValue;
                ?>
                <a class="button filter-link<?= $isActive ? ' active' : '' ?>"
                   href="?filter=<?= $filterValue ?>"
                   data-filter="<?= $filterValue ?>"
                   hx-get="?filter=<?= $filterValue ?>"
                   hx-target="#post-list"
                   hx-swap="outerHTML"
                   hx-push-url="true"><?= $label ?></a>
            <?php endforeach; ?>
        </nav>
        <form method="post">
            <a class="button" href="/fetch.php">Fetch</a>
            <input type="hidden" name="action" value="mark_all_read">
            <button type="submit">Alle als gelesen markieren</button>
        </form>
    </header>

    <?php $renderPostList(); ?>

    <form method="post">
        <input type="hidden" name="action" value="mark_all_read">
        <button type="submit">Alle als gelesen markieren</button>
    </form>
</body>
</html>
