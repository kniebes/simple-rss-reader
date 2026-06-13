<?php

declare(strict_types=1);

namespace Kniebes\SimpleRssReader\Util;

use DateTimeImmutable;
use DateTimeZone;

final class PostRenderer
{
    private const TEMPLATE_DIR = __DIR__.'/../../templates/components';

    public static function renderCard(array $post): string
    {
        $id = (int)$post['id'];
        $isFavorite = !empty($post['is_favorite']);
        $isNew = $post['status'] === 'new';
        $title = $post['title'] !== '' ? $post['title'] : ($post['permalink'] ?? $post['blog_url']);

        return self::render('post-card', [
            'id' => $id,
            'isNew' => $isNew,
            'title' => $title,
            'excerpt' => Text::excerpt($post['content'] ?? '', 140),
            'feedLinkHtml' => self::renderFeedLink($post),
            'datetime' => self::formatDatetime($post['date']),
            'favoriteButtonHtml' => self::renderFavoriteButton($id, $isFavorite),
            'unreadButtonHtml' => self::renderUnreadButton($id),
        ]);
    }

    public static function renderExpanded(array $post, string $sanitizedContent): string
    {
        $id = (int)$post['id'];
        $isFavorite = !empty($post['is_favorite']);
        $title = $post['title'] !== '' ? $post['title'] : ($post['permalink'] ?? $post['blog_url']);
        $permalink = $post['permalink'] !== null && Html::isSafeUrl((string)$post['permalink'])
            ? (string)$post['permalink']
            : null;

        return self::render('post-expanded', [
            'id' => $id,
            'title' => $title,
            'feedLinkHtml' => self::renderFeedLink($post),
            'datetime' => self::formatDatetime($post['date']),
            'favoriteButtonHtml' => self::renderFavoriteButton($id, $isFavorite),
            'unreadButtonHtml' => self::renderUnreadButton($id),
            'externalLinkHtml' => self::renderExternalLink($permalink),
            'collapseButtonHtml' => self::renderCollapseButton($id),
            'sanitizedContent' => $sanitizedContent,
        ]);
    }

    public static function renderFavoriteButton(int $id, bool $isFavorite): string
    {
        return self::render('favorite-button', [
            'id' => $id,
            'isFavorite' => $isFavorite,
        ]);
    }

    public static function renderUnreadButton(int $id): string
    {
        return self::render('unread-button', ['id' => $id]);
    }

    /**
     * @param array<int, array{title:string, posts:list<array>}> $sections
     */
    public static function renderList(array $sections): string
    {
        return self::render('post-list', ['sections' => $sections]);
    }

    private static function renderExternalLink(?string $permalink = null): string
    {
        return self::render('external-link', ['permalink' => $permalink]);
    }

    private static function renderCollapseButton(int $id): string
    {
        return self::render('collapse-button', ['id' => $id]);
    }

    private static function renderFeedLink(array $post): string
    {
        $blogUrl = (string)$post['blog_url'];
        $host = parse_url($blogUrl, PHP_URL_HOST) ?? $blogUrl;

        return self::render('feed-link', [
            'blogUrl' => $blogUrl,
            'host' => $host,
            'isSafe' => Html::isSafeUrl($blogUrl),
            'isShowLink' => false,
        ]);
    }

    private static function formatDatetime(string $utcDate): string
    {
        return (new DateTimeImmutable($utcDate, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Berlin'))
            ->format('Y-m-d H:i');
    }

    private static function render(string $template, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        require self::TEMPLATE_DIR.'/'.$template.'.phtml';

        return (string)ob_get_clean();
    }
}
