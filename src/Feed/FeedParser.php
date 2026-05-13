<?php

declare(strict_types=1);

namespace Kniebes\SimpleRssReader\Feed;

use DateTimeImmutable;
use RuntimeException;
use SimpleXMLElement;

final class FeedParser
{
    private const ATOM_NS = 'http://www.w3.org/2005/Atom';
    private const DC_NS = 'http://purl.org/dc/elements/1.1/';
    private const CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';

    /**
     * @return list<Entry>
     */
    public function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $root = simplexml_load_string($xml);
            if (!$root instanceof SimpleXMLElement) {
                throw new RuntimeException('Invalid XML');
            }

            $name = $root->getName();
            return match ($name) {
                'rss' => $this->parseRss($root),
                'feed' => $this->parseAtom($root),
                default => throw new RuntimeException("Unsupported feed root: {$name}"),
            };
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @return list<Entry>
     */
    private function parseRss(SimpleXMLElement $rss): array
    {
        $entries = [];
        foreach ($rss->channel->item as $item) {
            $link = trim((string) $item->link);
            $guid = trim((string) $item->guid);

            $dedupKey = $guid !== '' ? $guid : $link;
            if ($dedupKey === '') {
                continue;
            }

            $permalink = $link !== '' ? $link : null;
            if ($permalink === null && $guid !== '') {
                $isPermaLink = (string) ($item->guid['isPermaLink'] ?? 'true');
                if ($isPermaLink !== 'false') {
                    $permalink = $guid;
                }
            }

            $dateString = trim((string) $item->pubDate);
            if ($dateString === '') {
                $dc = $item->children(self::DC_NS);
                $dateString = trim((string) ($dc->date ?? ''));
            }

            $date = $this->parseDate($dateString);
            if ($date === null) {
                continue;
            }

            $contentNs = $item->children(self::CONTENT_NS);
            $content = trim((string) ($contentNs->encoded ?? ''));
            if ($content === '') {
                $content = trim((string) $item->description);
            }

            $entries[] = new Entry(
                date: $date,
                guid: $dedupKey,
                permalink: $permalink,
                title: trim((string) $item->title),
                content: $content,
            );
        }

        return $entries;
    }

    /**
     * @return list<Entry>
     */
    private function parseAtom(SimpleXMLElement $feed): array
    {
        $entries = [];
        foreach ($feed->entry as $entry) {
            $id = trim((string) $entry->id);
            $link = $this->pickAtomLink($entry);

            $dedupKey = $id !== '' ? $id : $link;
            if ($dedupKey === '') {
                continue;
            }

            $permalink = $link !== '' ? $link : null;

            $dateString = trim((string) $entry->updated);
            if ($dateString === '') {
                $dateString = trim((string) $entry->published);
            }

            $date = $this->parseDate($dateString);
            if ($date === null) {
                continue;
            }

            $content = trim((string) $entry->content);
            if ($content === '') {
                $content = trim((string) $entry->summary);
            }

            $entries[] = new Entry(
                date: $date,
                guid: $dedupKey,
                permalink: $permalink,
                title: trim((string) $entry->title),
                content: $content,
            );
        }

        return $entries;
    }

    private function pickAtomLink(SimpleXMLElement $entry): string
    {
        $fallback = '';
        foreach ($entry->link as $link) {
            $href = (string) ($link['href'] ?? '');
            if ($href === '') {
                continue;
            }
            $rel = (string) ($link['rel'] ?? 'alternate');
            if ($rel === 'alternate') {
                return $href;
            }
            if ($fallback === '') {
                $fallback = $href;
            }
        }
        return $fallback;
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
