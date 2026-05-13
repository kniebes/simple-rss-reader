<?php

declare(strict_types=1);

namespace Kniebes\SimpleRssReader\Storage;

use PDO;

final class Database
{
    public static function open(string $sqlitePath): PDO
    {
        $dir = dirname($sqlitePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $pdo = new PDO("sqlite:{$sqlitePath}", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');

        self::ensureSchema($pdo);

        return $pdo;
    }

    private static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS posts (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                date      TEXT NOT NULL,
                feed_url  TEXT NOT NULL,
                blog_url  TEXT NOT NULL,
                permalink TEXT NOT NULL UNIQUE,
                title     TEXT NOT NULL,
                content   TEXT NOT NULL DEFAULT '',
                status    TEXT NOT NULL DEFAULT 'new'
                          CHECK (status IN ('new','read'))
            )
        SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_posts_status_date ON posts(status, date DESC)');

        $columns = $pdo->query('PRAGMA table_info(posts)')->fetchAll();
        $existing = array_column($columns, 'name');
        if (!in_array('content', $existing, true)) {
            $pdo->exec("ALTER TABLE posts ADD COLUMN content TEXT NOT NULL DEFAULT ''");
        }
    }
}
