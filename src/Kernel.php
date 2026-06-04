<?php declare(strict_types=1);

namespace Kniebes\SimpleRssReader;

use Symfony\Component\Dotenv\Dotenv;

final class Kernel
{
    public static function environment(): void
    {
        $projectRoot = dirname(__DIR__);
        $envFile = $projectRoot . '/.env';
        if (is_file($envFile)) {
            (new Dotenv())->loadEnv($envFile);
        }
    }

    public static function getFileVersion(): string
    {
        $stamps = 0;
        foreach (['public/assets/css/site.css', 'public/assets/js/site.js', 'public/assets/js/htmx.min.js'] as $file) {
            $filePath = __DIR__ . '/../'. $file;
            if (file_exists($filePath)) {
                $stamps += filemtime($filePath);
            }
        }
        if (empty($stamps)) {
            return '';
        }

        return substr(md5((string) $stamps), 0, 8);
    }
}
