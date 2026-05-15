CREATE TABLE IF NOT EXISTS simeple_rss_reader_posts (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    date      DATETIME      NOT NULL,
    feed_url  VARCHAR(2048) NOT NULL,
    blog_url  VARCHAR(2048) NOT NULL,
    guid      VARCHAR(255)  NOT NULL UNIQUE,
    permalink VARCHAR(2048) NULL,
    title     TEXT          NOT NULL,
    content   MEDIUMTEXT    NOT NULL,
    status    ENUM('new','read') NOT NULL DEFAULT 'new',
    category  VARCHAR(64)   NULL,
    is_favorite TINYINT(1)  NOT NULL DEFAULT 0,
    INDEX idx_posts_status_date (status, date),
    INDEX idx_posts_category (category),
    INDEX idx_posts_favorite (is_favorite)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
