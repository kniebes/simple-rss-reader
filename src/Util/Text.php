<?php

declare(strict_types=1);

namespace Kniebes\SimpleRssReader\Util;

final class Text
{
    public static function excerpt(string $html, int $max = 280): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($html)));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $max)) . '…';
    }
}
