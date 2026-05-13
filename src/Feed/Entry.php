<?php

declare(strict_types=1);

namespace Kniebes\SimpleRssReader\Feed;

use DateTimeImmutable;

final readonly class Entry
{
    public function __construct(
        public DateTimeImmutable $date,
        public string $guid,
        public ?string $permalink,
        public string $title,
        public string $content,
    ) {
    }
}
