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
$favorite = ($_POST['favorite'] ?? '') === '1';

Kernel::environment();
Auth::requireLogin();

try {
    (new PostRepository(Database::open()))->setFavorite($id, $favorite);
} catch (Throwable $e) {
    http_response_code(500);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
echo PostRenderer::renderFavoriteButton($id, $favorite);
