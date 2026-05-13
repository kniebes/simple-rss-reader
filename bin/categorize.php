<?php

declare(strict_types=1);

use Kniebes\SimpleRssReader\Category\CategoryList;
use Kniebes\SimpleRssReader\Category\Classifier;
use Kniebes\SimpleRssReader\Storage\Database;
use Kniebes\SimpleRssReader\Storage\PostRepository;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$projectRoot = dirname(__DIR__);

$envFile = $projectRoot . '/.env';
if (is_file($envFile)) {
    (new Dotenv())->loadEnv($envFile);
}

$apiKey = (string) ($_ENV['ANTHROPIC_API_KEY'] ?? null);
if (empty($apiKey)) {
    fwrite(STDERR, "ANTHROPIC_API_KEY is not set. Hinterlege ihn in .env / .env.local oder als ENV-Variable.\n");
    exit(1);
}

$categories = CategoryList::fromFile($projectRoot . '/var/categories.md');
if ($categories->all() === []) {
    fwrite(STDERR, "var/categories.md ist leer oder enthält keine gültigen Einträge.\n");
    exit(1);
}

$repository = new PostRepository(Database::open());
$classifier = new Classifier(apiKey: $apiKey, categories: $categories);

$batchSize = 25;
$totalClassified = 0;
$totalNull = 0;

while (true) {
    $batch = $repository->findUncategorized(limit: $batchSize);
    if ($batch === []) {
        break;
    }

    try {
        $assignments = $classifier->classify($batch);
    } catch (Throwable $e) {
        fwrite(STDERR, "[FAIL] batch of " . count($batch) . ": {$e->getMessage()}\n");
        // den ersten Post explizit auf NULL bestätigen wäre falsch – wir brechen ab,
        // damit der nächste Lauf den Batch erneut versucht.
        exit(2);
    }

    foreach ($batch as $post) {
        $cat = $assignments[$post['id']] ?? null;
        // leerer String = bewusst klassifiziert ohne Match; verhindert Endlos-Loop bei IS NULL
        $repository->setCategory(id: $post['id'], category: $cat ?? '');
        if ($cat === null) {
            $totalNull++;
        } else {
            $totalClassified++;
        }
    }

    echo "[OK] batch of " . count($batch) . " classified (running total: {$totalClassified} matched, {$totalNull} null)\n";
}

echo "Done. {$totalClassified} matched, {$totalNull} without match.\n";
