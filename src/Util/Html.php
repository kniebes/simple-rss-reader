<?php

declare(strict_types=1);

namespace Kniebes\SimpleRssReader\Util;

final class Html
{
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function isSafeUrl(string $url): bool
    {
        // Nur absolute http(s)- oder protokoll-relative URLs als Link rendern —
        // verhindert javascript:/data:-Permalinks aus fremden Feeds.
        return preg_match('#^(https?://|//)#i', $url) === 1;
    }
}
