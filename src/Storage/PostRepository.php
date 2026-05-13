<?php

declare(strict_types=1);

namespace Kniebes\SimpleRssReader\Storage;

use Kniebes\SimpleRssReader\Feed\Entry;
use Kniebes\SimpleRssReader\Feed\Feed;
use PDO;

final class PostRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insertIgnore(Entry $entry, Feed $feed): bool
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO posts (date, feed_url, blog_url, permalink, title, content, status)
            VALUES (:date, :feed_url, :blog_url, :permalink, :title, :content, 'new')
            ON CONFLICT(permalink) DO UPDATE SET
                content = excluded.content
            WHERE posts.content = '' AND excluded.content <> ''
        SQL);
        $stmt->execute([
            ':date' => $entry->date->format(DATE_ATOM),
            ':feed_url' => $feed->feedUrl,
            ':blog_url' => $feed->blogUrl,
            ':permalink' => $entry->permalink,
            ':title' => $entry->title,
            ':content' => $entry->content,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<array{id:int,date:string,feed_url:string,blog_url:string,permalink:string,title:string,status:string}>
     */
    public function findByStatus(?string $status): array
    {
        if ($status === null) {
            $stmt = $this->pdo->query('SELECT * FROM posts ORDER BY date DESC');
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM posts WHERE status = :status ORDER BY date DESC');
            $stmt->execute([':status' => $status]);
        }

        return $stmt->fetchAll();
    }

    public function markAllRead(): int
    {
        $stmt = $this->pdo->prepare("UPDATE posts SET status = 'read' WHERE status = 'new'");
        $stmt->execute();

        return $stmt->rowCount();
    }
}
