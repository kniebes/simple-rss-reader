<?php

declare(strict_types=1);

namespace Kniebes\SimpleRssReader\Util;

use GuzzleHttp\Client;

final class HttpClient
{
    public const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:133.0) Gecko/20100101 Firefox/133.0';

    /**
     * @param array<string,mixed> $overrides
     */
    public static function create(array $overrides = []): Client
    {
        $defaults = [
            'timeout' => 10,
            'connect_timeout' => 5,
            'allow_redirects' => ['max' => 3],
            'headers' => ['User-Agent' => self::USER_AGENT],
            'http_errors' => false,
        ];

        return new Client(config: array_replace_recursive($defaults, $overrides));
    }
}
