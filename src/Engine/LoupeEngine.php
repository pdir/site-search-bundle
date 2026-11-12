<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Engine;

use Loupe\Loupe\SearchParameters;
use Pdir\SiteSearchBundle\Search\LoupeIndexFactory;

/**
 * Default engine: Loupe (SQLite), runs inside PHP, no external service. Supports
 * Loupe 0.4, 0.9 and 1.x.
 */
class LoupeEngine implements SearchEngineInterface
{
    public function __construct(private readonly LoupeIndexFactory $indexFactory)
    {
    }

    public function addDocuments(array $documents): array
    {
        return $this->indexFactory->addDocuments($documents);
    }

    public function deleteDocuments(array $urls): void
    {
        if ([] !== $urls) {
            $this->indexFactory->create()->deleteDocuments($urls);
        }
    }

    public function getDocument(string $url): array|null
    {
        return $this->indexFactory->create()->getDocument($url);
    }

    public function findUrls(Filter $filter): array
    {
        $urls = [];
        $page = 1;

        do {
            $result = $this->indexFactory->create()->search(
                SearchParameters::create()
                    ->withFilter(self::formatFilter($filter))
                    ->withAttributesToRetrieve(['url'])
                    ->withHitsPerPage(1000)
                    ->withPage($page),
            )->toArray();

            foreach ($result['hits'] ?? [] as $hit) {
                $urls[] = (string) $hit['url'];
            }
        } while ($page++ < (int) ($result['totalPages'] ?? 0));

        return $urls;
    }

    public function count(Filter $filter): int
    {
        $result = $this->indexFactory->create()->search(
            SearchParameters::create()
                ->withFilter(self::formatFilter($filter))
                ->withAttributesToRetrieve(['url'])
                ->withHitsPerPage(1),
        )->toArray();

        return (int) ($result['totalHits'] ?? 0);
    }

    public function search(string $query, Filter $filter, int $page, int $hitsPerPage, int $cropWords, string $highlightStart, string $highlightEnd): array
    {
        $parameters = SearchParameters::create()
            ->withQuery($query)
            ->withFilter(self::formatFilter($filter))
            // All fields, so additional provider fields (e.g. "category") are returned
            ->withAttributesToRetrieve(['*'])
            ->withAttributesToHighlight(IndexSchema::SEARCHABLE_ATTRIBUTES, $highlightStart, $highlightEnd)
            ->withSort(['_relevance:desc'])
            ->withPage($page)
            ->withHitsPerPage($hitsPerPage)
        ;

        // Loupe 1.x crops natively, for 0.x SiteSearch builds the snippets itself
        $cropped = method_exists($parameters, 'withAttributesToCrop');

        if ($cropped) {
            $parameters = $parameters->withAttributesToCrop(['description' => $cropWords, 'content' => $cropWords]);
        }

        $result = $this->indexFactory->create()->search($parameters)->toArray();

        return [
            'hits' => array_values($result['hits'] ?? []),
            'totalHits' => (int) ($result['totalHits'] ?? 0),
            'totalPages' => (int) ($result['totalPages'] ?? 0),
            'page' => (int) ($result['page'] ?? $page),
            'hitsPerPage' => (int) ($result['hitsPerPage'] ?? $hitsPerPage),
            'processingTimeMs' => (int) ($result['processingTimeMs'] ?? 0),
            'cropped' => $cropped,
        ];
    }

    public function drop(): void
    {
        $this->indexFactory->drop();
    }

    public function getInfo(): array
    {
        $storage = $this->indexFactory->getStorageInfo();

        try {
            $documents = file_exists($this->indexFactory->getStoragePath()) ? $this->indexFactory->create()->countDocuments() : 0;
        } catch (\Throwable) {
            $documents = null;
        }

        return [
            'engine' => 'Loupe',
            'location' => $this->indexFactory->getStoragePath(),
            'documents' => $documents,
            'size' => $storage['size'],
            'modified' => $storage['modified'],
        ];
    }

    /**
     * Loupe filter syntax: language = 'de' AND (protected = 0 OR groups = 2).
     */
    public static function formatFilter(Filter $filter): string
    {
        return $filter->format(
            static fn (string $field, int|string $value): string => $field.' = '.(\is_int($value) ? $value : "'".$value."'"),
            static fn (array $parts): string => '('.implode(' OR ', $parts).')',
            static fn (array $parts): string => implode(' AND ', $parts),
        );
    }
}
