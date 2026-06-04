<?php

declare(strict_types=1);

use Kniebes\SimpleRssReader\Kernel;
use Kniebes\SimpleRssReader\Storage\Database;
use Kniebes\SimpleRssReader\Storage\PostRepository;
use Kniebes\SimpleRssReader\Util\PostRenderer;

require __DIR__ . '/../vendor/autoload.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit;
}

Kernel::environment();

try {
    $post = (new PostRepository(Database::open()))->findById($id);
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
