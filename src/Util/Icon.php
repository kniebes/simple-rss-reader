<?php

declare(strict_types=1);

namespace Kniebes\SimpleRssReader\Util;

final class Icon
{
    private const ICON_DIR = __DIR__ . '/../../templates/components/icons';

    /** @var array<string, string> */
    private static array $cache = [];

    public static function render(string $name): string
    {
        if (!isset(self::$cache[$name])) {
            self::$cache[$name] = (string) file_get_contents(self::ICON_DIR . '/' . $name . '.svg');
        }

        return self::$cache[$name];
    }
}
