<?php

declare(strict_types=1);

namespace Kniebes\SimpleRssReader\Category;

final readonly class Category
{
    public function __construct(
        public string $name,
        public string $description,
        public int $relevance,
    ) {
    }
}
