<?php

declare(strict_types=1);

namespace Kniebes\SimpleRssReader\Category;

use RuntimeException;

final class CategoryList
{
    /**
     * @param list<Category> $categories
     */
    public function __construct(private readonly array $categories)
    {
    }

    public static function fromFile(string $path): self
    {
        if (!is_readable($path)) {
            throw new RuntimeException('categories file not readable: ' . $path);
        }

        $categories = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, '- ')) {
                $line = substr($line, 2);
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = trim(substr($line, 0, $colon));
            $description = trim(substr($line, $colon + 1));
            if ($name === '') {
                continue;
            }
            $categories[] = new Category(name: $name, description: $description);
        }

        return new self($categories);
    }

    /**
     * @return list<Category>
     */
    public function all(): array
    {
        return $this->categories;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (Category $category) => $category->name, $this->categories);
    }

    public function has(string $name): bool
    {
        foreach ($this->categories as $category) {
            if ($category->name === $name) {
                return true;
            }
        }
        return false;
    }
}
