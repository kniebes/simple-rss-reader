<?php

declare(strict_types=1);

use Kniebes\SimpleRssReader\Storage\Database;
use Kniebes\SimpleRssReader\Storage\PostRepository;

require __DIR__ . '/../vendor/autoload.php';

$repository = new PostRepository(Database::open(dirname(__DIR__) . '/var/posts.db'));

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

$posts = $repository->findByStatus($status);

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function excerpt(string $html, int $max = 280): string {
    $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($html)));
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    return rtrim(mb_substr($text, 0, $max)) . '…';
}

?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>RSS Reader</title>
    <style>
        body { font: 16px/1.5 -apple-system, system-ui, sans-serif; max-width: 50rem; margin: 2rem auto; padding: 0 1rem; color: #222; }
        header { display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; border-bottom: 1px solid #ddd; padding-bottom: .75rem; margin-bottom: 1.5rem; }
        header nav a { margin-right: .5rem; }
        header nav a.active { font-weight: 600; text-decoration: none; color: #000; }
        form { margin-left: auto; }
        button { font: inherit; padding: .3rem .75rem; cursor: pointer; }
        ul { list-style: none; padding: 0; }
        li { padding: .6rem 0; border-bottom: 1px solid #eee; }
        li .meta { font-size: .85rem; color: #666; }
        li .meta a { color: #666; }
        li .excerpt { font-size: .92rem; color: #444; margin: .25rem 0; }
        .empty { color: #888; padding: 2rem 0; text-align: center; }
        .new::before { content: "• "; color: #c33; }
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

    <?php if ($posts === []): ?>
        <p class="empty">Keine Posts.</p>
    <?php else: ?>
        <ul>
            <?php foreach ($posts as $post): ?>
                <li>
                    <div class="<?= $post['status'] === 'new' ? 'new' : '' ?>">
                        <a href="<?= e($post['permalink']) ?>" target="_blank" rel="noopener">
                            <?= e($post['title'] !== '' ? $post['title'] : $post['permalink']) ?>
                        </a>
                    </div>
                    <?php $exc = excerpt($post['content'] ?? ''); ?>
                    <?php if ($exc !== ''): ?>
                        <div class="excerpt"><?= e($exc) ?></div>
                    <?php endif; ?>
                    <div class="meta">
                        <?= e((new DateTimeImmutable($post['date']))->format('Y-m-d H:i')) ?>
                        ·
                        <a href="<?= e($post['blog_url']) ?>" target="_blank" rel="noopener">
                            <?= e(parse_url($post['blog_url'], PHP_URL_HOST) ?? $post['blog_url']) ?>
                        </a>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</body>
</html>
