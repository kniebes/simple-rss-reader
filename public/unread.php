<?php

declare(strict_types=1);

use Kniebes\SimpleRssReader\Kernel;
use Kniebes\SimpleRssReader\Storage\Database;
use Kniebes\SimpleRssReader\Storage\PostRepository;
use Kniebes\SimpleRssReader\Util\Auth;
use Kniebes\SimpleRssReader\Util\PostRenderer;

require __DIR__ . '/../vendor/autoload.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit;
}

Kernel::environment();
Auth::requireLogin();

try {
    $repository = new PostRepository(Database::open());
    $repository->markUnread($id);
    $post = $repository->findById($id);
    if ($post === null) {
        http_response_code(404);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
echo PostRenderer::renderCard($post);
