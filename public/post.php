<?php

declare(strict_types=1);

use Kniebes\SimpleRssReader\Kernel;
use Kniebes\SimpleRssReader\Storage\Database;
use Kniebes\SimpleRssReader\Storage\PostRepository;
use Kniebes\SimpleRssReader\Util\HtmlSanitizer;
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
    $post = $repository->findById($id);
    if ($post === null) {
        http_response_code(404);
        exit;
    }
    $repository->markRead($id);
} catch (Throwable $e) {
    http_response_code(500);
    exit;
}

$sanitized = HtmlSanitizer::safe((string) ($post['content'] ?? ''));

header('Content-Type: text/html; charset=utf-8');
echo PostRenderer::renderExpanded($post, $sanitized);
