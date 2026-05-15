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

$repository = new PostRepository(Database::open());
$categories = CategoryList::fromFile($projectRoot . '/var/categories.md');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    $repository->markAllRead();
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

$grouped = $repository->findGroupedByCategory($status, $onlyFavorites);
$totalCount = array_sum(array_map('count', $grouped));

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
                <a href="#<?= md5($section['title']) ?>"><?= e($section['title']) ?> <span class="count">(<?= count($section['posts']) ?>)</span></a>
            <?php endforeach; ?>
        </section>

        <?php foreach ($sections as $section): ?>
            <section>
                <h2 id="<?= md5($section['title']) ?>"><?= e($section['title']) ?> <span class="count">(<?= count($section['posts']) ?>)</span></h2>
                    <?php foreach ($section['posts'] as $post): ?>
                        <article>
                            <h3 class="<?= $post['status'] === 'new' ? 'new' : '' ?>">
                                <?php
                                $label = $post['title'] !== ''
                                    ? $post['title']
                                    : ($post['permalink'] ?? $post['blog_url']);
                                ?>
                                <?php if ($post['permalink'] !== null && $post['permalink'] !== ''): ?>
                                    <a rel="noreferrer" href="<?= e($post['permalink']) ?>" target="_blank" rel="noopener">
                                        <?= e($label) ?>
                                    </a>
                                <?php else: ?>
                                    <?= e($label) ?>
                                <?php endif; ?>
                            </h3>
                            <?php $exc = Text::excerpt($post['content'] ?? ''); ?>
                            <?php if ($exc !== ''): ?>
                                <div class="excerpt"><?= e($exc) ?></div>
                            <?php endif; ?>
                            <div class="meta">
                                <?= e((new DateTimeImmutable($post['date'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i')) ?>
                                ·
                                <a rel="noreferrer" href="<?= e($post['blog_url']) ?>" target="_blank" rel="noopener">
                                    <?= e(parse_url($post['blog_url'], PHP_URL_HOST) ?? $post['blog_url']) ?>
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
