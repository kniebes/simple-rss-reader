<?php

declare(strict_types=1);

namespace Kniebes\SimpleRssReader\Storage;

use DateTimeImmutable;
use DateTimeZone;
use Kniebes\SimpleRssReader\Feed\Entry;
use Kniebes\SimpleRssReader\Feed\Feed;
use PDO;

final class PostRepository
{
    private const MYSQL_DATETIME = 'Y-m-d H:i:s';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insertIgnore(Entry $entry, Feed $feed): bool
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO simeple_rss_reader_posts (date, feed_url, blog_url, guid, permalink, title, content, status)
            VALUES (:date, :feed_url, :blog_url, :guid, :permalink, :title, :content, 'new')
            ON DUPLICATE KEY UPDATE
                content = IF(simeple_rss_reader_posts.content = '' AND VALUES(content) <> '', VALUES(content), simeple_rss_reader_posts.content)
        SQL);
        $stmt->execute([
            ':date' => $entry->date->setTimezone(new DateTimeZone('UTC'))->format(self::MYSQL_DATETIME),
            ':feed_url' => $feed->feedUrl,
            ':blog_url' => $feed->blogUrl,
            ':guid' => $entry->guid,
            ':permalink' => $entry->permalink,
            ':title' => $entry->title,
            ':content' => $entry->content,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<array{id:int,date:string,feed_url:string,blog_url:string,guid:string,permalink:?string,title:string,status:string}>
     */
    public function findByStatus(?string $status): array
    {
        if ($status === null) {
            $stmt = $this->pdo->query('SELECT * FROM simeple_rss_reader_posts ORDER BY date DESC');
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM simeple_rss_reader_posts WHERE status = :status ORDER BY date DESC');
            $stmt->execute([':status' => $status]);
        }

        return $stmt->fetchAll();
    }

    public function markAllRead(): int
    {
        $stmt = $this->pdo->prepare("UPDATE simeple_rss_reader_posts SET status = 'read' WHERE status = 'new'");
        $stmt->execute();

        return $stmt->rowCount();
    }

    public function deleteOlderThanDays(int $days): int
    {
        $cutoff = (new DateTimeImmutable("-{$days} days", new DateTimeZone('UTC')))->format(self::MYSQL_DATETIME);
        $stmt = $this->pdo->prepare('DELETE FROM simeple_rss_reader_posts WHERE date < :cutoff AND is_favorite = 0');
        $stmt->execute([':cutoff' => $cutoff]);

        return $stmt->rowCount();
    }

    /**
     * @return list<array{id:int,title:string,content:string,blog_url:string}>
     */
    public function findUncategorized(int $limit = 0): array
    {
        $sql = "SELECT id, title, content, blog_url FROM simeple_rss_reader_posts WHERE category IS NULL AND status = 'new' ORDER BY date DESC";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }
        $stmt = $this->pdo->query($sql);

        return $stmt->fetchAll();
    }

    public function setCategory(int $id, ?string $category): void
    {
        $stmt = $this->pdo->prepare('UPDATE simeple_rss_reader_posts SET category = :cat WHERE id = :id');
        $stmt->execute([':cat' => $category, ':id' => $id]);
    }

    public function setFavorite(int $id, bool $favorite): void
    {
        $stmt = $this->pdo->prepare('UPDATE simeple_rss_reader_posts SET is_favorite = :fav WHERE id = :id');
        $stmt->execute([':fav' => $favorite ? 1 : 0, ':id' => $id]);
    }

    /**
     * @return array<string, list<array{id:int,date:string,feed_url:string,blog_url:string,guid:string,permalink:?string,title:string,content:string,status:string,category:?string,is_favorite:int}>>
     */
    public function findGroupedByCategory(?string $status, bool $onlyFavorites = false): array
    {
        if ($onlyFavorites) {
            $stmt = $this->pdo->query('SELECT * FROM simeple_rss_reader_posts WHERE is_favorite = 1 ORDER BY date DESC');
        } elseif ($status === null) {
            $stmt = $this->pdo->query('SELECT * FROM simeple_rss_reader_posts ORDER BY date DESC');
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM simeple_rss_reader_posts WHERE status = :status ORDER BY date DESC');
            $stmt->execute([':status' => $status]);
        }

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $key = ($row['category'] ?? '') !== '' ? (string) $row['category'] : '';
            $grouped[$key][] = $row;
        }

        return $grouped;
    }
}
