<?php

declare(strict_types=1);

namespace Kniebes\SimpleRssReader\Util;

use Exception;
use Graby\Graby;
use Http\Adapter\Guzzle7\Client as Guzzle7Adapter;
use InvalidArgumentException;
use RuntimeException;

final class FullContentExtractor
{
    /**
     * @throws Exception
     */
    public static function extract(string $url, int $timeoutSeconds = 8): string
    {
        if (!Html::isSafeUrl(url: $url)) {
            throw new InvalidArgumentException('Unsichere URL: ' . $url);
        }

        $guzzle = HttpClient::create(overrides: [
            'timeout' => $timeoutSeconds,
            'connect_timeout' => 4,
            'allow_redirects' => ['max' => 5],
        ]);

        $adapter = class_exists(Guzzle7Adapter::class) ? new Guzzle7Adapter($guzzle) : null;
        $graby = new Graby([], $adapter);
        $result = $graby->fetchContent($url);

        $status = (int) ($result['status'] ?? 0);
        if ($status >= 400) {
            throw new RuntimeException('HTTP ' . $status . ' beim Abruf von ' . $url);
        }

        $html = (string) ($result['html'] ?? '');
        // Graby gibt bei fehlgeschlagener Extraktion einen Sentinel-String zurück
        // statt zu werfen — den als Fehler durchreichen.
        if ($html === '' || $html === '[unable to retrieve full-text content]') {
            throw new RuntimeException('Extractor lieferte keinen verwertbaren Inhalt');
        }

        return $html;
    }
}
