<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Search;

use Loupe\Loupe\Config\TypoTolerance;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\Loupe;
use Loupe\Loupe\LoupeFactory;
use Pdir\SiteSearchBundle\Engine\IndexSchema;

/**
 * Creates the Loupe instance for the site search index.
 *
 * Indexer and search must use the same configuration, otherwise Loupe triggers a
 * reindex or rejects filters on attributes it does not know.
 *
 * Supports Loupe 0.4 (expects an existing SQLite file) as well as newer versions
 * like 0.9 and 1.x (expect a data directory).
 */
class LoupeIndexFactory
{
    public const PRIMARY_KEY = 'url';

    /**
     * Order matters: earlier attributes rank higher.
     */
    public const SEARCHABLE_ATTRIBUTES = IndexSchema::SEARCHABLE_ATTRIBUTES;

    public const FILTERABLE_ATTRIBUTES = IndexSchema::FILTERABLE_ATTRIBUTES;

    public const SORTABLE_ATTRIBUTES = IndexSchema::SORTABLE_ATTRIBUTES;

    private Loupe|null $loupe = null;

    /**
     * @param string $indexPath Data directory of the index; Loupe 0.4 uses "<indexPath>.sqlite"
     */
    public function __construct(private readonly string $indexPath)
    {
    }

    public function create(): Loupe
    {
        if (null !== $this->loupe) {
            return $this->loupe;
        }

        $path = $this->getStoragePath();

        if ($this->expectsDatabaseFile()) {
            if (!is_dir(\dirname($path))) {
                mkdir(\dirname($path), 0775, true);
            }

            if (!file_exists($path)) {
                touch($path);
            }
        }

        return $this->loupe = (new LoupeFactory())->create($path, self::createConfiguration());
    }

    /**
     * Deletes the whole index. The next call to create() starts with an empty index
     * and the current configuration.
     *
     * Deleting the files (instead of deleteAllDocuments()) also works with Loupe 0.4
     * and applies configuration changes like new filterable attributes.
     */
    public function drop(): void
    {
        // Release the SQLite connection before deleting the files
        $this->loupe = null;

        gc_collect_cycles();

        $path = $this->getStoragePath();

        if (is_file($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }

            rmdir($path);
        }
    }

    /**
     * Adds or replaces documents and returns the errors per document URL.
     *
     * Loupe 0.x does not throw for invalid documents but reports them in the
     * IndexResult; Loupe 1.x throws.
     *
     * @param list<array<string, mixed>> $documents
     *
     * @return array<string, string> Error message per URL
     */
    public function addDocuments(array $documents): array
    {
        if ([] === $documents) {
            return [];
        }

        $result = $this->create()->addDocuments($documents);

        if (!\is_object($result) || !method_exists($result, 'allDocumentExceptions')) {
            return [];
        }

        if (null !== ($exception = $result->generalException())) {
            throw $exception;
        }

        return array_map(static fn (\Throwable $e): string => $e->getMessage(), $result->allDocumentExceptions());
    }

    /**
     * Size of the index files in bytes and the time of the last change.
     *
     * @return array{size: int, modified: int|null}
     */
    public function getStorageInfo(): array
    {
        $path = $this->getStoragePath();
        $size = 0;
        $modified = null;

        $files = is_dir($path)
            ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS))
            : (is_file($path) ? [new \SplFileInfo($path)] : []);

        foreach ($files as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
                $modified = max($modified ?? 0, $file->getMTime());
            }
        }

        return ['size' => $size, 'modified' => $modified];
    }

    public function getStoragePath(): string
    {
        $path = rtrim($this->indexPath, '/');

        return $this->expectsDatabaseFile() ? $path.'.sqlite' : $path;
    }

    public static function createConfiguration(): Configuration
    {
        return Configuration::create()
            ->withPrimaryKey(self::PRIMARY_KEY)
            ->withSearchableAttributes(self::SEARCHABLE_ATTRIBUTES)
            ->withFilterableAttributes(self::FILTERABLE_ATTRIBUTES)
            ->withSortableAttributes(self::SORTABLE_ATTRIBUTES)
            ->withTypoTolerance(TypoTolerance::create()->withFirstCharTypoCountsDouble(false))
        ;
    }

    /**
     * @param array<mixed> $groups
     *
     * @return array{protected: int, groups: list<int>}
     *
     * @see IndexSchema::normalizeAccessFields()
     */
    public static function normalizeAccessFields(bool $protected, array $groups): array
    {
        return IndexSchema::normalizeAccessFields($protected, $groups);
    }

    /**
     * Loupe 0.4: create(string $dbPath), newer versions (0.9, 1.x):
     * create(string $dataDir).
     */
    private function expectsDatabaseFile(): bool
    {
        $parameters = (new \ReflectionMethod(LoupeFactory::class, 'create'))->getParameters();

        return 'dbPath' === ($parameters[0] ?? null)?->getName();
    }
}
