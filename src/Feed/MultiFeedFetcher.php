<?php

declare(strict_types=1);

namespace Kniebes\SimpleRssReader\Feed;

use Generator;

final class MultiFeedFetcher
{
    private const CONCURRENCY = 10;
    private const TIMEOUT = 10;
    private const CONNECT_TIMEOUT = 5;
    private const USER_AGENT = 'simple-rss-reader/0.1';

    /**
     * @param list<string> $urls
     * @return Generator<int, FetchResult>
     */
    public function fetchAll(array $urls): Generator
    {
        $mh = curl_multi_init();
        $queue = array_values($urls);
        $active = [];

        $start = function () use (&$queue, &$active, $mh): void {
            while (count($active) < self::CONCURRENCY && $queue !== []) {
                $url = array_shift($queue);
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_TIMEOUT => self::TIMEOUT,
                    CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                    CURLOPT_USERAGENT => self::USER_AGENT,
                    CURLOPT_ENCODING => '',
                ]);
                curl_multi_add_handle($mh, $ch);
                $active[(int) $ch] = $url;
            }
        };

        $start();

        $running = 0;
        while ($active !== []) {
            do {
                $status = curl_multi_exec($mh, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            if ($status !== CURLM_OK) {
                break;
            }

            if ($running > 0 || $queue !== []) {
                curl_multi_select($mh, 0.5);
            }

            while (($info = curl_multi_info_read($mh)) !== false) {
                $ch = $info['handle'];
                $key = (int) $ch;
                $url = $active[$key] ?? '';
                unset($active[$key]);

                $body = null;
                $error = null;

                if ($info['result'] === CURLE_OK) {
                    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    if ($code >= 200 && $code < 300) {
                        $body = (string) curl_multi_getcontent($ch);
                    } else {
                        $error = "HTTP {$code}";
                    }
                } else {
                    $error = curl_strerror($info['result']) ?: 'curl error';
                }

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                yield new FetchResult($url, $body, $error);

                $start();
            }
        }

        curl_multi_close($mh);
    }
}
