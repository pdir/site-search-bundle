<?php

declare(strict_types=1);

/*
 * Example: own source for companies from a custom table "company" with a
 * category table "category" (adjust the query to your schema).
 *
 * Copy to the host project (src/Search/CompanyDocumentProvider.php) and set the
 * base URL of the company pages, e.g. in .env.local:
 *
 *   COMPANY_BASE_URL=https://www.example.com/de/companies
 *
 * The class is registered automatically (autoconfigure), then:
 *
 *   php vendor/bin/contao-console pdir:site-search:reindex --source=company
 *
 * Search only companies: /search/results?keywords=heizung&source=company
 */

namespace App\Search;

use Doctrine\DBAL\Connection;
use Pdir\SiteSearchBundle\Source\RecordProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class CompanyDocumentProvider implements RecordProviderInterface
{
    private const CHUNK_SIZE = 1000;

    public function __construct(
        private readonly Connection $connection,
        #[Autowire(env: 'COMPANY_BASE_URL')]
        private readonly string $baseUrl,
    ) {
    }

    public function getSource(): string
    {
        return 'company';
    }

    /**
     * Records saved in the back end ("do=company") are updated in the index.
     */
    public function getTable(): string
    {
        return 'company';
    }

    public function getDocuments(): iterable
    {
        $lastId = 0;

        // In chunks: the table is too large to load at once
        do {
            $rows = $this->connection->fetchAllAssociative(
                $this->query().' WHERE c.published = 1 AND c.id > ? ORDER BY c.id LIMIT '.self::CHUNK_SIZE,
                [$lastId],
            );

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];

                if (null !== ($document = $this->createDocument($row))) {
                    yield $document;
                }
            }
        } while (\count($rows) === self::CHUNK_SIZE);
    }

    public function getDocument(int $id): array|null
    {
        $row = $this->connection->fetchAssociative($this->query().' WHERE c.published = 1 AND c.id = ?', [$id]);

        return false === $row ? null : $this->createDocument($row);
    }

    private function query(): string
    {
        return 'SELECT c.id, c.name, c.website, c.description, cat.name AS category FROM company c LEFT JOIN category cat ON cat.id = c.category';
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    private function createDocument(array $row): array|null
    {
        $category = trim((string) $row['category'], '/');

        if ('' === trim((string) $row['name']) || '' === $category) {
            return null;
        }

        $segments = explode('/', $category);

        return [
            'id' => (int) $row['id'],
            // The company is listed on its category page (<li id="item123">)
            'url' => rtrim($this->baseUrl, '/').'/'.implode('/', array_map('rawurlencode', $segments)).'#item'.$row['id'],
            'title' => (string) $row['name'],
            'content' => (string) $row['description'],
            'language' => 'de',
            // Returned with the hit, e.g. for the old API format
            'website' => (string) $row['website'],
            'category' => str_replace('_', ' ', (string) end($segments)),
        ];
    }
}
