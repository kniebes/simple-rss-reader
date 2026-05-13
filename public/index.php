<?php

declare(strict_types=1);

use Kniebes\SimpleRssReader\Category\CategoryList;
use Kniebes\SimpleRssReader\Storage\Database;
use Kniebes\SimpleRssReader\Storage\PostRepository;
use Kniebes\SimpleRssReader\Util\Text;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$projectRoot = dirname(__DIR__);

$envFile = $projectRoot . '/.env';
if (is_file($envFile)) {
    (new Dotenv())->loadEnv($envFile);
}

$repository = new PostRepository(Database::open());
$categories = CategoryList::fromFile($projectRoot . '/var/categories.md');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    $repository->markAllRead();
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/'), true, 303);
    exit;
}

$filter = $_GET['filter'] ?? 'new';
$status = match ($filter) {
    'read' => 'read',
    'all' => null,
    default => 'new',
};
$filter = match ($status) {
    'read' => 'read',
    null => 'all',
    default => 'new',
};

$grouped = $repository->findGroupedByCategory($status);
$totalCount = array_sum(array_map('count', $grouped));

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>RSS Reader</title>
    <style>
        body { font: 1.4rem/1.8rem -apple-system, system-ui, sans-serif; max-width: 50rem; margin: 2rem auto; padding: 0 1rem; color: #222; }
        header { font-size: .9rem; display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; border-bottom: 1px solid #ddd; padding-bottom: .75rem; margin-bottom: 1.5rem; }
        header nav a { margin-right: .5rem; }
        header nav a.active { font-weight: 600; text-decoration: none; color: #000; }
        form { margin-left: auto; }
        button { font: inherit; padding: .3rem .75rem; cursor: pointer; border: none; background: transparent; }
        section { margin-bottom: 2rem; }
        section h2 { font-size: 1.05rem; margin: 0 0 .5rem; padding-bottom: .25rem; border-bottom: 1px solid #ddd; color: #555; }
        section h2 .count { color: #999; font-weight: normal; font-size: .9em; }
        ul { list-style: none; padding: 0; margin: 0; }
        li { padding: .6rem 0; border-bottom: 1px solid #eee; }
        li .meta { font-size: .85rem; color: #666; }
        li .meta a { color: #666; }
        li .excerpt { font-size: 1.2rem; color: #444; margin: .25rem 0; }
        .empty { color: #888; padding: 2rem 0; text-align: center; }
        .new::before { content: "• "; color: #3c3; }
        a { color: darkorange; text-decoration: none; }
        a:visited { color: #888; }
    </style>
</head>
<body>
    <header>
        <nav>
            <a href="?filter=new"<?= $filter === 'new' ? ' class="active"' : '' ?>>Neu</a>
            <a href="?filter=read"<?= $filter === 'read' ? ' class="active"' : '' ?>>Gelesen</a>
            <a href="?filter=all"<?= $filter === 'all' ? ' class="active"' : '' ?>>Alle</a>
        </nav>
        <form method="post">
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
        <?php foreach ($sections as $section): ?>
            <section>
                <h2><?= e($section['title']) ?> <span class="count">(<?= count($section['posts']) ?>)</span></h2>
                <ul>
                    <?php foreach ($section['posts'] as $post): ?>
                        <li>
                            <div class="<?= $post['status'] === 'new' ? 'new' : '' ?>">
                                <?php
                                $label = $post['title'] !== ''
                                    ? $post['title']
                                    : ($post['permalink'] ?? $post['blog_url']);
                                ?>
                                <?php if ($post['permalink'] !== null && $post['permalink'] !== ''): ?>
                                    <a href="<?= e($post['permalink']) ?>" target="_blank" rel="noopener">
                                        <?= e($label) ?>
                                    </a>
                                <?php else: ?>
                                    <?= e($label) ?>
                                <?php endif; ?>
                            </div>
                            <?php $exc = Text::excerpt($post['content'] ?? ''); ?>
                            <?php if ($exc !== ''): ?>
                                <div class="excerpt"><?= e($exc) ?></div>
                            <?php endif; ?>
                            <div class="meta">
                                <?= e((new DateTimeImmutable($post['date'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i')) ?>
                                ·
                                <a href="<?= e($post['blog_url']) ?>" target="_blank" rel="noopener">
                                    <?= e(parse_url($post['blog_url'], PHP_URL_HOST) ?? $post['blog_url']) ?>
                                </a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
