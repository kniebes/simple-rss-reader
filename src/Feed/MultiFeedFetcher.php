<?php

declare(strict_types=1);

namespace Kniebes\SimpleRssReader\Feed;

use DateTimeImmutable;
use GuzzleHttp\Pool;
use Kniebes\SimpleRssReader\Storage\PostRepository;
use Kniebes\SimpleRssReader\Util\HttpClient;
use Kniebes\SimpleRssReader\Util\Streaming;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class MultiFeedFetcher
{
    private const CONCURRENCY = 10;
    private const TIMEOUT = 10;
    private const CONNECT_TIMEOUT = 5;

    /**
     * @param list<string> $urls
     * @param callable(FetchResult): void $onResult
     */
    public function fetchAll(array $urls, callable $onResult): void
    {
        $client = HttpClient::create(overrides: [
            'timeout' => self::TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'allow_redirects' => ['max' => 3],
        ]);

        $requests = static function () use ($client, $urls) {
            foreach ($urls as $index => $url) {
                yield $index => static fn() => $client->getAsync($url);
            }
        };

        $pool = new Pool(client: $client, requests: $requests(), config: [
            'concurrency' => self::CONCURRENCY,
            'fulfilled' => static function (ResponseInterface $response, int $index) use ($urls, $onResult): void {
                $url = $urls[$index];
                $status = $response->getStatusCode();
                if ($status >= 200 && $status < 300) {
                    $onResult(new FetchResult($url, (string)$response->getBody(), null));

                    return;
                }
                $onResult(new FetchResult($url, null, 'HTTP '.$status));
            },
            'rejected' => static function (Throwable $reason, int $index) use ($urls, $onResult): void {
                $onResult(new FetchResult($urls[$index], null, $reason->getMessage()));
            },
        ]);

        $pool->promise()->wait();
    }

    /**
     * Parst den FetchResult-Body, fügt neue Entries (jünger als $cutoff) ins
     * Repository ein und tickt eine [OK]/[FAIL]-Zeile über Streaming raus.
     * Returns die Anzahl der frisch eingefügten Entries.
     */
    public static function processResult(
        FetchResult $result,
        Feed $feed,
        FeedParser $feedParser,
        PostRepository $repository,
        DateTimeImmutable $cutoff,
        string $prefix,
    ): int {
        if ($result->body === null) {
            Streaming::tick($prefix.' [FAIL] '.$result->url.': '.$result->error.'<br>');

            return 0;
        }

        try {
            $entries = $feedParser->parse($result->body);
        } catch (Throwable $e) {
            Streaming::tick($prefix.' [FAIL] '.$result->url.': '.$e->getMessage().'<br>');

            return 0;
        }

        $newCount = 0;
        $skipped = 0;
        foreach ($entries as $entry) {
            if ($entry->date < $cutoff) {
                continue;
            }
            try {
                if ($repository->insertIgnore($entry, $feed)) {
                    $newCount++;
                }
            } catch (Throwable $e) {
                Streaming::tick($prefix.' [FAIL] '.$result->url.': '.$e->getMessage().'<br>');
                // Einzelnes Item überspringen statt den ganzen Lauf abzubrechen —
                // z. B. guid/permalink länger als die Spalte (strict mode wirft).
                $skipped++;
            }
        }

        $note = $skipped > 0
            ? sprintf(' (%d new, %d skipped)', $newCount, $skipped)
            : sprintf(' (%d new)', $newCount);
        Streaming::tick($prefix.' [OK] '.$result->url.$note.'<br>');

        return $newCount;
    }
}
