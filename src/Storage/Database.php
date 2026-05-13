<?php

declare(strict_types=1);

namespace Kniebes\SimpleRssReader\Storage;

use PDO;
use RuntimeException;

final class Database
{
    public static function open(): PDO
    {
        $url = (string) ($_ENV['DATABASE_URL'] ?? '');
        if ($url === '') {
            throw new RuntimeException('DATABASE_URL is not set. Hinterlege ihn in .env / .env.local oder als ENV-Variable.');
        }

        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'mysql') {
            throw new RuntimeException('DATABASE_URL must be a mysql:// URL');
        }

        $host = $parts['host'] ?? 'db';
        $port = (int) ($parts['port'] ?? 3306);
        $dbname = ltrim((string) ($parts['path'] ?? ''), '/');
        $user = rawurldecode((string) ($parts['user'] ?? ''));
        $pass = rawurldecode((string) ($parts['pass'] ?? ''));

        if ($dbname === '') {
            throw new RuntimeException('DATABASE_URL is missing the database name (path)');
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $pdo;
    }
}
