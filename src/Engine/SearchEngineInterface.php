<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Engine;

/**
 * Storage and search of the site search documents.
 *
 * Implementations: Loupe (default, local SQLite, no external service),
 * Meilisearch and Typesense. Selected via the DSN in pdir_site_search.engine.
 *
 * Documents are arrays as built by the indexers (see IndexSchema); the URL is the
 * unique key.
 */
interface SearchEngineInterface
{
    /**
     * Adds or replaces documents.
     *
     * @param list<array<string, mixed>> $documents
     *
     * @return array<string, string> Error message per URL of documents that were not indexed
     */
    public function addDocuments(array $documents): array;

    /**
     * @param list<string> $urls
     */
    public function deleteDocuments(array $urls): void;

    /**
     * @return array<string, mixed>|null
     */
    public function getDocument(string $url): array|null;

    /**
     * URLs of all documents matching the filter.
     *
     * @return list<string>
     */
    public function findUrls(Filter $filter): array;

    public function count(Filter $filter): int;

    /**
     * Full-text search, sorted by relevance.
     *
     * Hits contain the stored fields plus "_formatted" with highlighted (and, if
     * "cropped" is true, cropped) values of title, description and content. Field
     * values in "_formatted" are plain text except for the highlight tags.
     *
     * @return array{hits: list<array<string, mixed>>, totalHits: int, totalPages: int, page: int, hitsPerPage: int, processingTimeMs: int, cropped: bool}
     */
    public function search(string $query, Filter $filter, int $page, int $hitsPerPage, int $cropWords, string $highlightStart, string $highlightEnd): array;

    /**
     * Deletes all documents and (re)creates the index with the current schema.
     */
    public function drop(): void;

    /**
     * For the back end: engine name, location and figures.
     *
     * @return array{engine: string, location: string, documents: int|null, size: int|null, modified: int|null}
     */
    public function getInfo(): array;
}
