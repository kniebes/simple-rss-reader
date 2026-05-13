<?php

declare(strict_types=1);

namespace Kniebes\SimpleRssReader\Feed;

final readonly class Feed
{
    public function __construct(
        public string $feedUrl,
        public string $blogUrl,
    ) {
    }
}
