<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Search;

use Pdir\SiteSearchBundle\Engine\Filter;
use Pdir\SiteSearchBundle\Engine\IndexSchema;
use Pdir\SiteSearchBundle\Engine\SearchEngineInterface;

/**
 * Runs a search against the site search index.
 *
 * - sorted by relevance (Loupe default)
 * - only documents in the requested language and of the requested domain
 * - protected documents only for members of one of their groups
 * - description is returned as a short snippet (from the meta description or
 *   the page text), never as full page text
 */
class SiteSearch
{
    public const MAX_QUERY_LENGTH = 200;

    public const MAX_HITS_PER_PAGE = 50;

    public const SNIPPET_WORDS = 30;

    private const HIGHLIGHT_START = '<em>';

    private const HIGHLIGHT_END = '</em>';

    public function __construct(private readonly SearchEngineInterface $engine)
    {
    }

    /**
     * @param list<int>    $memberGroups Group IDs of the logged-in member (empty for guests)
     * @param list<string> $sources      Only documents of these sources, e.g. ["page", "news"] (empty = all)
     *
     * @return array{hits: list<array<string, mixed>>, query: string, page: int, hitsPerPage: int, totalPages: int, totalHits: int, processingTimeMs: int}
     */
    public function search(string $query, string|null $language = null, array $memberGroups = [], int $page = 1, int $hitsPerPage = 10, string|null $domain = null, array $sources = []): array
    {
        $query = trim(mb_substr($query, 0, self::MAX_QUERY_LENGTH));
        $page = max(1, $page);
        $hitsPerPage = max(1, min(self::MAX_HITS_PER_PAGE, $hitsPerPage));

        $empty = [
            'hits' => [],
            'query' => $query,
            'page' => $page,
            'hitsPerPage' => $hitsPerPage,
            'totalPages' => 0,
            'totalHits' => 0,
            'processingTimeMs' => 0,
        ];

        // An empty query would return the whole index
        if ('' === $query) {
            return $empty;
        }

        $result = $this->engine->search(
            $query,
            $this->buildFilter($language, $memberGroups, $domain, $sources),
            $page,
            $hitsPerPage,
            self::SNIPPET_WORDS,
            self::HIGHLIGHT_START,
            self::HIGHLIGHT_END,
        );

        $hits = [];

        foreach ($result['hits'] as $hit) {
            $hits[] = $this->prepareHit($hit, $query, $result['cropped']);
        }

        return [
            'hits' => $hits,
            'query' => $query,
            'page' => $result['page'],
            'hitsPerPage' => $result['hitsPerPage'],
            'totalPages' => $result['totalPages'],
            'totalHits' => $result['totalHits'],
            'processingTimeMs' => $result['processingTimeMs'],
        ];
    }

    /**
     * @param list<int>    $memberGroups
     * @param list<string> $sources
     */
    public function buildFilter(string|null $language, array $memberGroups, string|null $domain = null, array $sources = []): Filter
    {
        return Filter::create(
            domain: $this->normalizeDomain($domain),
            language: $this->normalizeLanguage($language),
            sources: $sources,
            memberGroups: $memberGroups,
        );
    }

    /**
     * Accepts "de", "de-DE", "de_DE" and returns "de"; anything else returns null.
     */
    public function normalizeLanguage(string|null $language): string|null
    {
        if (null === $language || !preg_match('/^([a-z]{2})(?:[-_][a-z0-9]{2,8})?$/i', trim($language), $matches)) {
            return null;
        }

        return strtolower($matches[1]);
    }

    /**
     * Accepts host names like "www.example.com"; anything else returns null.
     */
    public function normalizeDomain(string|null $domain): string|null
    {
        $domain = strtolower(trim((string) $domain));

        return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $domain) ? $domain : null;
    }

    /**
     * @param array<string, mixed> $hit
     *
     * @return array<string, mixed>
     */
    private function prepareHit(array $hit, string $query, bool $nativeCrop): array
    {
        $formatted = \is_array($hit['_formatted'] ?? null) ? $hit['_formatted'] : [];
        $snippets = [];

        foreach (['description', 'content'] as $attribute) {
            $text = (string) ($hit[$attribute] ?? '');

            if ('' === trim($text)) {
                continue;
            }

            if ($nativeCrop && isset($formatted[$attribute])) {
                $snippets[$attribute] = (string) $formatted[$attribute];
            } else {
                // Loupe 0.x has no cropping: build the snippet ourselves
                $snippets[$attribute] = $this->highlight($this->createSnippet($text, $query), $query);
            }
        }

        // Prefer the meta description if it contains a match, then the page text around
        // the match
        $snippet = $snippets['description'] ?? '';

        if (!str_contains($snippet, self::HIGHLIGHT_START) && str_contains($snippets['content'] ?? '', self::HIGHLIGHT_START)) {
            $snippet = $snippets['content'];
        } elseif ('' === $snippet) {
            $snippet = $snippets['content'] ?? '';
        }

        // Never return the full page text or internal fields
        foreach (IndexSchema::INTERNAL_FIELDS as $field) {
            unset($hit[$field]);
        }

        $hit['description'] = $this->stripHighlight($snippet);

        // HTML-safe: everything is escaped except the highlight tags
        $hit['_formatted'] = [
            'title' => $this->escapeHighlighted((string) ($formatted['title'] ?? $hit['title'] ?? '')),
            'description' => $this->escapeHighlighted($snippet),
        ];

        return $hit;
    }

    private function createSnippet(string $text, string $query): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $words = '' === $text ? [] : explode(' ', $text);

        if (\count($words) <= self::SNIPPET_WORDS) {
            return $text;
        }

        // Start shortly before the first word that contains a query term
        $start = 0;
        $terms = $this->getQueryTerms($query);

        foreach ($words as $index => $word) {
            foreach ($terms as $term) {
                if (false !== mb_stripos($word, $term)) {
                    $start = max(0, $index - 5);
                    break 2;
                }
            }
        }

        $start = min($start, \count($words) - self::SNIPPET_WORDS);
        $snippet = implode(' ', \array_slice($words, $start, self::SNIPPET_WORDS));

        return ($start > 0 ? '… ' : '').$snippet.($start + self::SNIPPET_WORDS < \count($words) ? ' …' : '');
    }

    private function highlight(string $text, string $query): string
    {
        $terms = $this->getQueryTerms($query);

        if ([] === $terms) {
            return $text;
        }

        $pattern = '/('.implode('|', array_map(static fn (string $term): string => preg_quote($term, '/'), $terms)).')/iu';

        return (string) preg_replace($pattern, self::HIGHLIGHT_START.'$1'.self::HIGHLIGHT_END, $text);
    }

    private function escapeHighlighted(string $text): string
    {
        return str_replace(
            [htmlspecialchars(self::HIGHLIGHT_START), htmlspecialchars(self::HIGHLIGHT_END)],
            [self::HIGHLIGHT_START, self::HIGHLIGHT_END],
            htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    private function stripHighlight(string $text): string
    {
        return str_replace([self::HIGHLIGHT_START, self::HIGHLIGHT_END], '', $text);
    }

    /**
     * @return list<string>
     */
    private function getQueryTerms(string $query): array
    {
        $terms = preg_split('/[\s"]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Ignore negated terms ("-word") and very short terms
        $terms = array_values(array_filter($terms, static fn (string $term): bool => !str_starts_with($term, '-') && mb_strlen($term) >= 2));

        // Longest first, so "Vertrag" wins over "Vert"
        usort($terms, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $terms;
    }
}
