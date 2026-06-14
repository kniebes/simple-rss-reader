<?php

declare(strict_types=1);

use Kniebes\SimpleRssReader\Category\CategoryList;
use Kniebes\SimpleRssReader\Category\Classifier;
use Kniebes\SimpleRssReader\Kernel;
use Kniebes\SimpleRssReader\Storage\Database;
use Kniebes\SimpleRssReader\Storage\PostRepository;
use Kniebes\SimpleRssReader\Util\Auth;
use Kniebes\SimpleRssReader\Util\Streaming;

require __DIR__ . '/../vendor/autoload.php';

Kernel::environment();
Auth::requireLogin();

Streaming::begin(title: 'categorize');

$projectRoot = dirname(__DIR__);

$categories = CategoryList::fromFile($projectRoot . '/var/categories.md');
if ($categories->all() === []) {
    Streaming::tick('[FAIL] var/categories.md ist leer oder enthält keine gültigen Einträge.<br>');
    echo '<a href="/">Home</a>';
    exit(1);
}

try {
    $repository = new PostRepository(Database::open());
    $classifier = new Classifier(
        apiKey: (string) ($_ENV['ANTHROPIC_API_KEY'] ?? ''),
        categories: $categories,
    );
} catch (Throwable $e) {
    Streaming::tick('[FATAL] ' . $e->getMessage() . '<br>');
    echo '<a href="/">Home</a>';
    exit(1);
}

$batchSize = 25;
$totalClassified = 0;
$totalNull = 0;

while (true) {
    try {
        $batch = $repository->findUncategorized(limit: $batchSize);
    } catch (Throwable $e) {
        Streaming::tick('[FATAL] DB read: ' . $e->getMessage() . '<br>');
        echo '<a href="/">Home</a>';
        exit(2);
    }

    if ($batch === []) {
        break;
    }

    try {
        $assignments = $classifier->classify($batch);
    } catch (Throwable $e) {
        Streaming::tick('[FAIL] batch of ' . count($batch) . ': ' . $e->getMessage() . '<br>');
        // den ersten Post explizit auf NULL bestätigen wäre falsch – wir brechen ab,
        // damit der nächste Lauf den Batch erneut versucht.
        echo '<a href="/">Home</a>';
        exit(2);
    }

    foreach ($batch as $post) {
        $cat = $assignments[$post['id']] ?? null;
        try {
            // leerer String = bewusst klassifiziert ohne Match; verhindert Endlos-Loop bei IS NULL
            $repository->setCategory(id: $post['id'], category: $cat ?? '');
        } catch (Throwable $e) {
            // Einzelnen Post überspringen, damit der Rest des Batches (bereits per API klassifiziert) nicht verloren geht.
            Streaming::tick('[WARN] post ' . $post['id'] . ': ' . $e->getMessage() . '<br>');
            continue;
        }
        if ($cat === null) {
            $totalNull++;
        } else {
            $totalClassified++;
        }
    }

    Streaming::tick('[OK] batch of ' . count($batch) . ' classified (running total: ' . $totalClassified . ' matched, ' . $totalNull . ' null)<br>');
}

Streaming::tick('Done. ' . $totalClassified . ' matched, ' . $totalNull . ' without match.<br>');
echo '<a href="/">Home</a>';
