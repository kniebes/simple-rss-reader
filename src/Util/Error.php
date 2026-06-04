<?php

declare(strict_types=1);

namespace Kniebes\SimpleRssReader\Util;

final class Error
{
    public static function render(int $status, string $title, string $message): never
    {
        http_response_code($status);
        require dirname(__DIR__, 2) . '/templates/error.phtml';
        exit;
    }
}
