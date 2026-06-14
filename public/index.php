<?php

declare(strict_types=1);

use Kniebes\SimpleRssReader\Category\CategoryList;
use Kniebes\SimpleRssReader\Kernel;
use Kniebes\SimpleRssReader\Storage\Database;
use Kniebes\SimpleRssReader\Storage\PostRepository;
use Kniebes\SimpleRssReader\Util\Auth;
use Kniebes\SimpleRssReader\Util\Error;
use Kniebes\SimpleRssReader\Util\PostRenderer;

require __DIR__ . '/../vendor/autoload.php';

$projectRoot = dirname(__DIR__);

Kernel::environment();
Auth::requireLogin();

try {
    $repository = new PostRepository(Database::open());
} catch (Throwable $e) {
    Error::render(503, 'Reader nicht verfügbar', $e->getMessage());
}
$categories = CategoryList::fromFile($projectRoot . '/var/categories.md');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    try {
        $repository->markAllRead();
    } catch (Throwable $e) {
        Error::render(500, 'Markieren fehlgeschlagen', $e->getMessage());
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
    Error::render(503, 'Reader nicht verfügbar', $e->getMessage());
}

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

if ($isHtmxRequest) {
    header('Content-Type: text/html; charset=utf-8');
    echo PostRenderer::renderList($sections);
    exit;
}

require $projectRoot . '/templates/index.phtml';
