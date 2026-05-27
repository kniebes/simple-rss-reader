<?php

declare(strict_types=1);

namespace Kniebes\SimpleRssReader\Feed;

final readonly class FetchResult
{
    public function __construct(
        public string $url,
        public ?string $body,
        public ?string $error,
    ) {
    }
}
