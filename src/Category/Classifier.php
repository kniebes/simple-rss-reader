<?php

declare(strict_types=1);

namespace Kniebes\SimpleRssReader\Category;

use Kniebes\SimpleRssReader\Util\Text;
use RuntimeException;

final class Classifier
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const MODEL = 'claude-haiku-4-5-20251001';

    public function __construct(
        private readonly string $apiKey,
        private readonly CategoryList $categories,
    ) {
        if ($this->apiKey === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY ist nicht gesetzt. Hinterlege ihn in .env / .env.local oder als ENV-Variable.');
        }
    }

    /**
     * @param list<array{id:int,title:string,content:string,blog_url:string}> $posts
     * @return array<int, ?string> map of post id to category name (or null if no match)
     */
    public function classify(array $posts): array
    {
        if ($posts === []) {
            return [];
        }

        $payload = [
            'model' => self::MODEL,
            'max_tokens' => 2048,
            'system' => [
                [
                    'type' => 'text',
                    'text' => $this->buildSystemPrompt(),
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
            'messages' => [
                ['role' => 'user', 'content' => $this->buildUserMessage($posts)],
                ['role' => 'assistant', 'content' => '['],
            ],
        ];

        $response = $this->postJson($payload);
        $text = '[' . ($response['content'][0]['text'] ?? '');

        return $this->parseAssignments($text);
    }

    private function buildSystemPrompt(): string
    {
        $lines = ['Du klassifizierst Blog-Posts in genau eine der folgenden Kategorien:' . "\n"];
        foreach ($this->categories->all() as $category) {
            $lines[] = '- ' . $category->name . ': ' . $category->description;
        }
        $lines[] = '';
        $lines[] = 'Antworte ausschließlich mit einem JSON-Array. Jedes Element hat das Schema:';
        $lines[] = '{"id": <int>, "category": "<exakter Kategorie-Name oder null>"}';
        $lines[] = 'Wenn kein Match klar ist, setze category auf null.';
        $lines[] = 'Keine Kommentare, kein Markdown, keine Code-Fences. Nur das JSON-Array.';

        return implode("\n", $lines);
    }

    /**
     * @param list<array{id:int,title:string,content:string,blog_url:string}> $posts
     */
    private function buildUserMessage(array $posts): string
    {
        $items = [];
        foreach ($posts as $post) {
            $items[] = [
                'id' => $post['id'],
                'title' => $post['title'],
                'excerpt' => Text::excerpt($post['content'], 280),
                'source' => parse_url($post['blog_url'], PHP_URL_HOST) ?: $post['blog_url'],
            ];
        }
        return 'Klassifiziere folgende Posts:' . "\n\n" . json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * @return array<int, ?string>
     */
    private function parseAssignments(string $text): array
    {
        $text = trim($text);
        if (!str_ends_with($text, ']')) {
            $lastBracket = strrpos($text, ']');
            if ($lastBracket === false) {
                throw new RuntimeException('No JSON array found in response: ' . substr($text, 0, 200));
            }
            $text = substr($text, 0, $lastBracket + 1);
        }

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON in response: ' . substr($text, 0, 200));
        }

        $result = [];
        foreach ($decoded as $item) {
            if (!is_array($item) || !isset($item['id'])) {
                continue;
            }
            $id = (int) $item['id'];
            $cat = $item['category'] ?? null;
            if (is_string($cat) && $cat !== '' && $this->categories->has($cat)) {
                $result[$id] = $cat;
            } else {
                $result[$id] = null;
            }
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function postJson(array $payload): array
    {
        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Anthropic API request failed: ' . $err);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Anthropic API HTTP ' . $status . ': ' . substr($body, 0, 500));
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Anthropic API returned non-JSON body');
        }

        return $decoded;
    }
}
