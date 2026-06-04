<?php

declare(strict_types=1);

namespace Kniebes\SimpleRssReader\Util;

use HTMLPurifier;
use HTMLPurifier_Config;

final class Html
{
    private static ?HTMLPurifier $purifier = null;

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

    public static function sanitize(string $html): string
    {
        return self::purifier()->purify($html);
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier !== null) {
            return self::$purifier;
        }

        $cacheDir = dirname(__DIR__, 2) . '/var/cache/htmlpurifier';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'a[href|title],p,br,strong,em,b,i,u,ul,ol,li,blockquote,cite,code,pre,h2,h3,h4,h5,h6,img[src|alt|title|width|height],table,thead,tbody,tr,th,td,hr');
        $config->set('HTML.TargetBlank', true);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.AllowedFrameTargets', []);
        $config->set('Attr.DefaultImageAlt', '');
        $config->set('AutoFormat.RemoveEmpty', true);
        $config->set('Cache.SerializerPath', $cacheDir);

        return self::$purifier = new HTMLPurifier($config);
    }
}
