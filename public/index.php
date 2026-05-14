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
    <title>Reader</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="/assets/css/site.css?<?= Kernel::getFileVersion() ?>">
</head>
<body>
    <header>
        <h1>Simple Feed Reader</h1>
        <nav>
            <a href="?filter=new"<?= $filter === 'new' ? ' class="active"' : '' ?>>Neu</a>
            <a href="?filter=read"<?= $filter === 'read' ? ' class="active"' : '' ?>>Gelesen</a>
            <a href="?filter=all"<?= $filter === 'all' ? ' class="active"' : '' ?>>Alle</a>
            <a href="/fetch.php">Fetch</a>
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
                            </div>
                        </article>
                    <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
