<?php

declare(strict_types=1);

use Kniebes\SimpleRssReader\Category\CategoryList;
use Kniebes\SimpleRssReader\Category\Classifier;
use Kniebes\SimpleRssReader\Kernel;
use Kniebes\SimpleRssReader\Storage\Database;
use Kniebes\SimpleRssReader\Storage\PostRepository;

require __DIR__ . '/../vendor/autoload.php';

set_time_limit(0);

// Streaming-Setup: gzip/deflate aus, alle Puffer-Ebenen schließen, implicit flush an.
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', 'Off');
header('Content-Encoding: none');
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

// Mini-HTML-Preamble + Padding, damit Firefox/Safari den Render-Threshold (~1 KB) erreichen.
echo "<!doctype html><meta charset=utf-8><title>categorize</title>\n";
echo str_repeat(' ', 1024) . "\n";
flush();

// Apache mod_proxy_fcgi puffert FastCGI-Pakete ohne flushpackets=on;
// per-line padding drückt jede Zeile über die Puffer-Schwelle und erzwingt Auslieferung.
$tick = static function (string $line): void {
    echo $line . str_repeat(' ', 4096) . "\n";
};

$projectRoot = dirname(__DIR__);

Kernel::environment();

$apiKey = (string) ($_ENV['ANTHROPIC_API_KEY'] ?? '');
if ($apiKey === '') {
    $tick('[FAIL] ANTHROPIC_API_KEY is not set. Hinterlege ihn in .env / .env.local oder als ENV-Variable.<br>');
    exit(1);
}

$categories = CategoryList::fromFile($projectRoot . '/var/categories.md');
if ($categories->all() === []) {
    $tick('[FAIL] var/categories.md ist leer oder enthält keine gültigen Einträge.<br>');
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
        $tick("[FAIL] batch of " . count($batch) . ": {$e->getMessage()}<br>");
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

    $tick("[OK] batch of " . count($batch) . " classified (running total: {$totalClassified} matched, {$totalNull} null)<br>");
}

$tick("Done. {$totalClassified} matched, {$totalNull} without match.<br>");
echo '<a href="/">Home</a>';
