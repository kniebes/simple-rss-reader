<?php

declare(strict_types=1);

namespace Kniebes\SimpleRssReader\Opml;

use Kniebes\SimpleRssReader\Feed\Feed;
use RuntimeException;
use SimpleXMLElement;

final class OpmlReader
{
    /**
     * @return list<Feed>
     */
    public function readFeeds(string $opmlPath): array
    {
        if (!is_readable($opmlPath)) {
            throw new RuntimeException('OPML file not readable: ' . $opmlPath);
        }

        $xml = @simplexml_load_file($opmlPath);
        if (!$xml instanceof SimpleXMLElement) {
            throw new RuntimeException('Failed to parse OPML: ' . $opmlPath);
        }

        $feeds = [];
        $this->collect($xml->body, $feeds);

        return $feeds;
    }

    /**
     * @param list<Feed> $feeds
     */
    private function collect(SimpleXMLElement $node, array &$feeds): void
    {
        foreach ($node->outline as $outline) {
            $xmlUrl = (string) ($outline['xmlUrl'] ?? '');
            if ($xmlUrl !== '') {
                $feeds[] = new Feed(
                    feedUrl: $xmlUrl,
                    blogUrl: (string) ($outline['htmlUrl'] ?? ''),
                );
            }
            if (isset($outline->outline)) {
                $this->collect($outline, $feeds);
            }
        }
    }
}
