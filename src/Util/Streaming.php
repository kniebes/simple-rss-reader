<?php

declare(strict_types=1);

namespace Kniebes\SimpleRssReader\Util;

final class Streaming
{
    public static function begin(string $title): void
    {
        set_time_limit(0);

        // Streaming-Setup: gzip/deflate aus, alle Puffer-Ebenen schließen, implicit flush an.
        if (function_exists('apache_setenv')) {
            apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', 'Off');
        header('Content-Encoding: none');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);

        // Mini-HTML-Preamble + Padding, damit Firefox/Safari den Render-Threshold (~1 KB) erreichen.
        echo '<!doctype html><meta charset=utf-8><title>' . Html::escape($title) . '</title>' . "\n";
        echo str_repeat(' ', 1024) . "\n";
        flush();
    }

    // Apache mod_proxy_fcgi puffert FastCGI-Pakete ohne flushpackets=on;
    // per-line padding drückt jede Zeile über die Puffer-Schwelle und erzwingt Auslieferung.
    public static function tick(string $line): void
    {
        echo $line . str_repeat(' ', 4096) . "\n";
    }
}
