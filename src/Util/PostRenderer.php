<?php

declare(strict_types=1);

namespace Kniebes\SimpleRssReader\Util;

use DateTimeImmutable;
use DateTimeZone;

final class PostRenderer
{
    public static function renderCard(array $post): string
    {
        $id = (int) $post['id'];
        $isFavorite = !empty($post['is_favorite']);
        $isNew = $post['status'] === 'new';
        $title = $post['title'] !== ''
            ? $post['title']
            : ($post['permalink'] ?? $post['blog_url']);
        $excerpt = Text::excerpt($post['content'] ?? '', 140);

        ob_start();
        ?>
        <article class="card<?= $isNew ? ' is-new' : '' ?>"
                 data-post-id="<?= $id ?>"
                 hx-get="/post.php?id=<?= $id ?>"
                 hx-target="this"
                 hx-swap="outerHTML">
            <div class="title"><?= Html::escape($title) ?></div>
            <div class="feed"><?= self::renderFeedLink($post) ?></div>
            <div class="datetime"><?= self::renderDatetime($post) ?></div>
            <div class="actions">
                <?php if (!$isNew): ?>
                    <?= self::renderUnreadButton($id) ?>
                <?php endif; ?>
                <?= self::renderFavoriteButton($id, $isFavorite) ?>
            </div>
            <?php if ($excerpt !== ''): ?>
                <div class="excerpt"><?= Html::escape($excerpt) ?></div>
            <?php endif; ?>
        </article>
        <?php
        return (string) ob_get_clean();
    }

    public static function renderUnreadButton(int $id): string
    {
        return '<button type="button"'
            . ' class="icon-button unread-toggle"'
            . ' hx-post="/unread.php"'
            . ' hx-vals=\'{"id": "' . $id . '"}\''
            . ' hx-target="closest article"'
            . ' hx-swap="outerHTML"'
            . ' hx-trigger="click consume"'
            . ' aria-label="Als ungelesen markieren"'
            . ' title="Als ungelesen markieren">↺</button>';
    }

    public static function renderExpanded(array $post, string $sanitizedContent): string
    {
        $id = (int) $post['id'];
        $isFavorite = !empty($post['is_favorite']);
        $title = $post['title'] !== ''
            ? $post['title']
            : ($post['permalink'] ?? $post['blog_url']);
        $hasPermalink = $post['permalink'] !== null && Html::isSafeUrl((string) $post['permalink']);

        ob_start();
        ?>
        <article class="expanded" data-post-id="<?= $id ?>">
            <div class="title"><?= Html::escape($title) ?></div>
            <div class="feed"><?= self::renderFeedLink($post) ?></div>
            <div class="datetime"><?= self::renderDatetime($post) ?></div>
            <div class="actions">
                <?= self::renderUnreadButton($id) ?>
                <?= self::renderFavoriteButton($id, $isFavorite) ?>
                <?php if ($hasPermalink): ?>
                    <a class="icon-button external" rel="noopener noreferrer" target="_blank"
                       href="<?= Html::escape((string) $post['permalink']) ?>"
                       title="Original öffnen">↗</a>
                <?php endif; ?>
                <button type="button"
                        class="icon-button collapse"
                        hx-get="/post-card.php?id=<?= $id ?>"
                        hx-target="closest article"
                        hx-swap="outerHTML"
                        aria-label="Schließen"
                        title="Schließen">×</button>
            </div>
            <div class="content"><?= $sanitizedContent ?></div>
        </article>
        <?php
        return (string) ob_get_clean();
    }

    private static function renderFeedLink(array $post): string
    {
        $blogUrl = (string) $post['blog_url'];
        $host = parse_url($blogUrl, PHP_URL_HOST) ?? $blogUrl;
        if (Html::isSafeUrl($blogUrl)) {
            return '<a rel="noopener noreferrer" target="_blank"'
                . ' href="' . Html::escape($blogUrl) . '"'
                . ' onclick="event.stopPropagation()">' . Html::escape($host) . '</a>';
        }
        return Html::escape($host);
    }

    private static function renderDatetime(array $post): string
    {
        $formatted = (new DateTimeImmutable($post['date'], new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Berlin'))
            ->format('Y-m-d H:i');
        return Html::escape($formatted);
    }

    public static function renderFavoriteButton(int $id, bool $isFavorite): string
    {
        $nextFavorite = $isFavorite ? '0' : '1';
        $classes = 'icon-button favorite-toggle' . ($isFavorite ? ' is-favorite' : '');
        $pressed = $isFavorite ? 'true' : 'false';
        $glyph = $isFavorite ? '★' : '☆';

        return '<button type="button"'
            . ' class="' . $classes . '"'
            . ' hx-post="/favorite.php"'
            . ' hx-vals=\'{"id": "' . $id . '", "favorite": "' . $nextFavorite . '"}\''
            . ' hx-target="this"'
            . ' hx-swap="outerHTML"'
            . ' hx-trigger="click consume"'
            . ' aria-pressed="' . $pressed . '"'
            . ' aria-label="Favorit"'
            . ' title="Favorit">' . $glyph . '</button>';
    }
}
