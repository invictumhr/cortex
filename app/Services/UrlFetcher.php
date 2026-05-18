<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class UrlFetcher
{
    /**
     * Fetch a URL and extract its readable title, text and preview image.
     *
     * @return array{url: string, title: string, content: string, image: ?string}
     */
    public function fetch(string $url): array
    {
        $response = Http::timeout((int) config('cortex.url_fetch.timeout', 20))
            ->withHeaders(['User-Agent' => 'CortexBot/1.0 (+https://cortex.local)'])
            ->get($url);

        if ($response->failed()) {
            throw new RuntimeException("Dohvat URL-a nije uspio (HTTP {$response->status()}): {$url}");
        }

        $html = $response->body();

        return [
            'url' => $url,
            'title' => $this->extractTitle($html) ?: $url,
            'image' => $this->extractOgImage($html),
            'content' => $this->extractText($html),
        ];
    }

    private function extractTitle(string $html): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return null;
    }

    private function extractOgImage(string $html): ?string
    {
        if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
            return $m[1];
        }

        return null;
    }

    private function extractText(string $html): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        foreach (['script', 'style', 'noscript', 'nav', 'footer', 'header', 'aside', 'svg', 'form'] as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                $node?->parentNode?->removeChild($node);
            }
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        $text = $body ? $body->textContent : $dom->textContent;
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');

        return mb_substr($text, 0, (int) config('cortex.url_fetch.max_content_length', 20000));
    }
}
