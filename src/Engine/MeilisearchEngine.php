<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Engine;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Meilisearch (≥ 1.2) via its REST API.
 *
 * DSN: meilisearch://<api key>@<host>:<port>?index=<uid>&tls=1
 *
 * Meilisearch only accepts [a-zA-Z0-9_-] as document ID, so the ID is the SHA-1
 * of the URL. Write operations wait for their task, so the index is consistent
 * when a method returns (e.g. counts in the back end right after a reindex).
 */
class MeilisearchEngine implements SearchEngineInterface
{
    private const TASK_TIMEOUT = 60;

    private bool $indexChecked = false;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly string $apiKey = '',
        private readonly string $index = 'pdir_site_search',
    ) {
    }

    public function addDocuments(array $documents): array
    {
        if ([] === $documents) {
            return [];
        }

        $this->ensureIndex();

        $payload = array_map(static fn (array $document): array => ['id' => IndexSchema::documentId((string) $document['url']), ...$document], $documents);
        $task = $this->waitForTask($this->request('POST', '/indexes/'.$this->index.'/documents?primaryKey=id', $payload));

        if ('succeeded' === $task['status']) {
            return [];
        }

        // Meilisearch rejects the whole batch
        $message = (string) ($task['error']['message'] ?? 'Unknown Meilisearch error');

        return array_fill_keys(array_map(static fn (array $document): string => (string) $document['url'], $documents), $message);
    }

    public function deleteDocuments(array $urls): void
    {
        if ([] === $urls) {
            return;
        }

        foreach (array_chunk($urls, 1000) as $chunk) {
            $this->waitForTask($this->request('POST', '/indexes/'.$this->index.'/documents/delete-batch', array_map(IndexSchema::documentId(...), $chunk), [404]));
        }
    }

    public function getDocument(string $url): array|null
    {
        $document = $this->request('GET', '/indexes/'.$this->index.'/documents/'.IndexSchema::documentId($url), null, [404]);

        if (null === $document) {
            return null;
        }

        unset($document['id']);

        return $document;
    }

    public function findUrls(Filter $filter): array
    {
        $urls = [];
        $offset = 0;

        do {
            $result = $this->request(
                'POST',
                '/indexes/'.$this->index.'/documents/fetch',
                [
                    ...$this->filterParameter($filter),
                    'fields' => ['url'],
                    'limit' => 1000,
                    'offset' => $offset,
                ],
                [404],
            ) ?? [];

            foreach ($result['results'] ?? [] as $document) {
                $urls[] = (string) $document['url'];
            }

            $offset += 1000;
        } while ($offset < (int) ($result['total'] ?? 0));

        return $urls;
    }

    public function count(Filter $filter): int
    {
        $result = $this->request('POST', '/indexes/'.$this->index.'/documents/fetch', [...$this->filterParameter($filter), 'fields' => ['url'], 'limit' => 1], [404]) ?? [];

        return (int) ($result['total'] ?? 0);
    }

    public function search(string $query, Filter $filter, int $page, int $hitsPerPage, int $cropWords, string $highlightStart, string $highlightEnd): array
    {
        $result = $this->request(
            'POST',
            '/indexes/'.$this->index.'/search',
            [
                'q' => $query,
                ...$this->filterParameter($filter),
                'page' => $page,
                'hitsPerPage' => $hitsPerPage,
                'attributesToHighlight' => IndexSchema::SEARCHABLE_ATTRIBUTES,
                'highlightPreTag' => $highlightStart,
                'highlightPostTag' => $highlightEnd,
                'attributesToCrop' => ['description', 'content'],
                'cropLength' => $cropWords,
                'cropMarker' => '…',
            ],
            [404],
        ) ?? [];

        return [
            'hits' => array_values($result['hits'] ?? []),
            'totalHits' => (int) ($result['totalHits'] ?? 0),
            'totalPages' => (int) ($result['totalPages'] ?? 0),
            'page' => (int) ($result['page'] ?? $page),
            'hitsPerPage' => (int) ($result['hitsPerPage'] ?? $hitsPerPage),
            'processingTimeMs' => (int) ($result['processingTimeMs'] ?? 0),
            'cropped' => true,
        ];
    }

    public function drop(): void
    {
        $this->waitForTask($this->request('DELETE', '/indexes/'.$this->index, null, [404]));

        $this->indexChecked = false;

        $this->ensureIndex();
    }

    public function getInfo(): array
    {
        $stats = $this->request('GET', '/indexes/'.$this->index.'/stats', null, [404]);

        return [
            'engine' => 'Meilisearch',
            'location' => rtrim($this->baseUrl, '/').'/indexes/'.$this->index,
            'documents' => null === $stats ? 0 : (int) ($stats['numberOfDocuments'] ?? 0),
            'size' => null,
            'modified' => null,
        ];
    }

    /**
     * Meilisearch filter syntax: language = 'de' AND (protected = 0 OR groups = 2).
     */
    public static function formatFilter(Filter $filter): string
    {
        return $filter->format(
            static fn (string $field, int|string $value): string => $field.' = '.(\is_int($value) ? $value : "'".$value."'"),
            static fn (array $parts): string => '('.implode(' OR ', $parts).')',
            static fn (array $parts): string => implode(' AND ', $parts),
        );
    }

    /**
     * @return array{filter?: string}
     */
    private function filterParameter(Filter $filter): array
    {
        $expression = self::formatFilter($filter);

        return '' === $expression ? [] : ['filter' => $expression];
    }

    private function ensureIndex(): void
    {
        if ($this->indexChecked) {
            return;
        }

        if (null === $this->request('GET', '/indexes/'.$this->index, null, [404])) {
            $this->waitForTask($this->request('POST', '/indexes', ['uid' => $this->index, 'primaryKey' => 'id']));
        }

        $this->waitForTask($this->request(
            'PATCH',
            '/indexes/'.$this->index.'/settings',
            [
                'searchableAttributes' => IndexSchema::SEARCHABLE_ATTRIBUTES,
                'filterableAttributes' => IndexSchema::FILTERABLE_ATTRIBUTES,
                'sortableAttributes' => IndexSchema::SORTABLE_ATTRIBUTES,
            ],
        ));

        $this->indexChecked = true;
    }

    /**
     * @param array<string, mixed>|null $task
     *
     * @return array<string, mixed>
     */
    private function waitForTask(array|null $task): array
    {
        if (!isset($task['taskUid'])) {
            return ['status' => 'succeeded'];
        }

        $start = microtime(true);
        $delay = 10000;

        while (true) {
            $status = $this->request('GET', '/tasks/'.$task['taskUid']);

            if (\in_array($status['status'] ?? '', ['succeeded', 'failed', 'canceled'], true)) {
                return $status;
            }

            if (microtime(true) - $start > self::TASK_TIMEOUT) {
                throw new \RuntimeException(\sprintf('Meilisearch task %s did not finish within %d seconds.', $task['taskUid'], self::TASK_TIMEOUT));
            }

            usleep($delay);

            $delay = min(250000, $delay * 2);
        }
    }

    /**
     * @param list<int> $allowedErrors Status codes that return null instead of throwing
     *
     * @return array<mixed>|null
     */
    private function request(string $method, string $path, mixed $json = null, array $allowedErrors = []): array|null
    {
        $options = ['headers' => ['Accept' => 'application/json']];

        if ('' !== $this->apiKey) {
            $options['headers']['Authorization'] = 'Bearer '.$this->apiKey;
        }

        if (null !== $json) {
            $options['json'] = $json;
        }

        $response = $this->httpClient->request($method, rtrim($this->baseUrl, '/').$path, $options);
        $status = $response->getStatusCode();

        if (\in_array($status, $allowedErrors, true)) {
            return null;
        }

        $content = $response->getContent(false);

        if ($status >= 400) {
            $error = json_decode($content, true);

            throw new \RuntimeException(\sprintf('Meilisearch %s %s failed (%d): %s', $method, $path, $status, \is_array($error) ? ($error['message'] ?? $content) : $content));
        }

        return '' === $content ? [] : (array) json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
