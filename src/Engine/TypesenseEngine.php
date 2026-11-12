<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Engine;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Typesense (≥ 0.25) via its REST API.
 *
 * DSN: typesense://<api key>@<host>:<port>?collection=<name>&tls=1
 *
 * Fields not in the collection schema (url, image, provider fields) are stored
 * but not indexed. The document ID is the SHA-1 of the URL.
 */
class TypesenseEngine implements SearchEngineInterface
{
    private bool $collectionChecked = false;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly string $apiKey = '',
        private readonly string $collection = 'pdir_site_search',
    ) {
    }

    public function addDocuments(array $documents): array
    {
        if ([] === $documents) {
            return [];
        }

        $this->ensureCollection();

        $lines = array_map(static fn (array $document): string => json_encode(['id' => IndexSchema::documentId((string) $document['url']), ...$document], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $documents);

        $response = $this->send('POST', '/collections/'.$this->collection.'/documents/import?action=upsert', ['body' => implode("\n", $lines), 'headers' => ['Content-Type' => 'text/plain']]);
        $errors = [];

        // One JSON line per document, in the same order
        foreach (explode("\n", trim($response)) as $index => $line) {
            $result = json_decode($line, true);

            if (!\is_array($result) || true !== ($result['success'] ?? false)) {
                $errors[(string) ($documents[$index]['url'] ?? '#'.$index)] = (string) ($result['error'] ?? 'Unknown Typesense error');
            }
        }

        return $errors;
    }

    public function deleteDocuments(array $urls): void
    {
        foreach (array_chunk($urls, 100) as $chunk) {
            $ids = implode(',', array_map(IndexSchema::documentId(...), $chunk));
            $this->send('DELETE', '/collections/'.$this->collection.'/documents?'.http_build_query(['filter_by' => 'id:['.$ids.']']), [], [404]);
        }
    }

    public function getDocument(string $url): array|null
    {
        $document = $this->json($this->send('GET', '/collections/'.$this->collection.'/documents/'.IndexSchema::documentId($url), [], [404]));

        if (null === $document) {
            return null;
        }

        unset($document['id']);

        return $document;
    }

    public function findUrls(Filter $filter): array
    {
        $query = ['include_fields' => 'url'];

        if ('' !== ($expression = self::formatFilter($filter))) {
            $query['filter_by'] = $expression;
        }

        $export = $this->send('GET', '/collections/'.$this->collection.'/documents/export?'.http_build_query($query), [], [404]);
        $urls = [];

        foreach (explode("\n", trim((string) $export)) as $line) {
            if ('' !== $line && \is_array($document = json_decode($line, true)) && isset($document['url'])) {
                $urls[] = (string) $document['url'];
            }
        }

        return $urls;
    }

    public function count(Filter $filter): int
    {
        $query = ['q' => '*', 'query_by' => 'title', 'per_page' => 0];

        if ('' !== ($expression = self::formatFilter($filter))) {
            $query['filter_by'] = $expression;
        }

        $result = $this->json($this->send('GET', '/collections/'.$this->collection.'/documents/search?'.http_build_query($query), [], [404])) ?? [];

        return (int) ($result['found'] ?? 0);
    }

    public function search(string $query, Filter $filter, int $page, int $hitsPerPage, int $cropWords, string $highlightStart, string $highlightEnd): array
    {
        $parameters = [
            'q' => $query,
            'query_by' => implode(',', IndexSchema::SEARCHABLE_ATTRIBUTES),
            // Same order of importance as with Loupe and Meilisearch
            'query_by_weights' => '4,2,1',
            // Typo tolerance like Loupe and Meilisearch: 1 typo from 5, 2 from 9 characters
            // (Typesense default: 4 and 7, "dacj" would already match "dach")
            'min_len_1typo' => 5,
            'min_len_2typo' => 9,
            'page' => $page,
            'per_page' => $hitsPerPage,
            'highlight_fields' => implode(',', IndexSchema::SEARCHABLE_ATTRIBUTES),
            'highlight_full_fields' => 'title',
            'highlight_start_tag' => $highlightStart,
            'highlight_end_tag' => $highlightEnd,
            'highlight_affix_num_tokens' => max(1, intdiv($cropWords, 2)),
            'snippet_threshold' => $cropWords,
        ];

        if ('' !== ($expression = self::formatFilter($filter))) {
            $parameters['filter_by'] = $expression;
        }

        $result = $this->json($this->send('GET', '/collections/'.$this->collection.'/documents/search?'.http_build_query($parameters), [], [404])) ?? [];
        $hits = [];

        foreach ($result['hits'] ?? [] as $hit) {
            $document = (array) ($hit['document'] ?? []);
            $document['_formatted'] = $this->formatHighlights($hit);

            $hits[] = $document;
        }

        $found = (int) ($result['found'] ?? 0);

        return [
            'hits' => $hits,
            'totalHits' => $found,
            'totalPages' => (int) ceil($found / max(1, $hitsPerPage)),
            'page' => (int) ($result['page'] ?? $page),
            'hitsPerPage' => $hitsPerPage,
            'processingTimeMs' => (int) ($result['search_time_ms'] ?? 0),
            // Fields without a match have no snippet, SiteSearch crops them itself
            'cropped' => true,
        ];
    }

    public function drop(): void
    {
        $this->send('DELETE', '/collections/'.$this->collection, [], [404]);

        $this->collectionChecked = false;

        $this->ensureCollection();
    }

    public function getInfo(): array
    {
        $collection = $this->json($this->send('GET', '/collections/'.$this->collection, [], [404]));

        return [
            'engine' => 'Typesense',
            'location' => rtrim($this->baseUrl, '/').'/collections/'.$this->collection,
            'documents' => null === $collection ? 0 : (int) ($collection['num_documents'] ?? 0),
            'size' => null,
            'modified' => null,
        ];
    }

    /**
     * Typesense filter syntax: language:=`de` && (protected:=0 || groups:=2).
     */
    public static function formatFilter(Filter $filter): string
    {
        return $filter->format(
            static fn (string $field, int|string $value): string => $field.':='.(\is_int($value) ? $value : '`'.$value.'`'),
            static fn (array $parts): string => '('.implode(' || ', $parts).')',
            static fn (array $parts): string => implode(' && ', $parts),
        );
    }

    /**
     * @param array<string, mixed> $hit
     *
     * @return array<string, string>
     */
    private function formatHighlights(array $hit): array
    {
        $formatted = [];

        // Typesense ≥ 0.25: "highlight" object; older versions: "highlights" list
        foreach ((array) ($hit['highlight'] ?? []) as $field => $highlight) {
            if (\is_array($highlight) && \in_array($field, IndexSchema::SEARCHABLE_ATTRIBUTES, true)) {
                $formatted[$field] = (string) ('title' === $field ? ($highlight['value'] ?? $highlight['snippet'] ?? '') : ($highlight['snippet'] ?? ''));
            }
        }

        foreach ((array) ($hit['highlights'] ?? []) as $highlight) {
            $field = (string) ($highlight['field'] ?? '');

            if (!isset($formatted[$field]) && \in_array($field, IndexSchema::SEARCHABLE_ATTRIBUTES, true)) {
                $formatted[$field] = (string) ('title' === $field ? ($highlight['value'] ?? $highlight['snippet'] ?? '') : ($highlight['snippet'] ?? ''));
            }
        }

        return array_filter($formatted, static fn (string $value): bool => '' !== $value);
    }

    private function ensureCollection(): void
    {
        if ($this->collectionChecked) {
            return;
        }

        if (null === $this->send('GET', '/collections/'.$this->collection, [], [404])) {
            $string = static fn (string $name, bool $facet = false): array => ['name' => $name, 'type' => 'string', 'facet' => $facet];

            $this->send(
                'POST',
                '/collections',
                [
                    'json' => [
                        'name' => $this->collection,
                        'fields' => [
                            $string('title'),
                            $string('description'),
                            $string('content'),
                            $string('domain', true),
                            $string('language', true),
                            $string('source', true),
                            $string('record', true),
                            ['name' => 'protected', 'type' => 'int32'],
                            ['name' => 'groups', 'type' => 'int32[]'],
                        ],
                    ],
                ],
                [409],
            );
        }

        $this->collectionChecked = true;
    }

    /**
     * @return array<mixed>|null
     */
    private function json(string|null $content): array|null
    {
        return null === $content ? null : (array) json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $options
     * @param list<int>            $allowedErrors Status codes that return null instead of throwing
     */
    private function send(string $method, string $path, array $options = [], array $allowedErrors = []): string|null
    {
        $options['headers'] = ['Accept' => 'application/json', ...($options['headers'] ?? [])];

        if ('' !== $this->apiKey) {
            $options['headers']['X-TYPESENSE-API-KEY'] = $this->apiKey;
        }

        $response = $this->httpClient->request($method, rtrim($this->baseUrl, '/').$path, $options);
        $status = $response->getStatusCode();

        if (\in_array($status, $allowedErrors, true)) {
            return null;
        }

        $content = $response->getContent(false);

        if ($status >= 400) {
            $error = json_decode($content, true);

            throw new \RuntimeException(\sprintf('Typesense %s %s failed (%d): %s', $method, strtok($path, '?'), $status, \is_array($error) ? ($error['message'] ?? $content) : $content));
        }

        return $content;
    }
}
