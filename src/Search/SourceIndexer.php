<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Search;

use Pdir\SiteSearchBundle\Engine\Filter;
use Pdir\SiteSearchBundle\Engine\IndexSchema;
use Pdir\SiteSearchBundle\Engine\SearchEngineInterface;
use Pdir\SiteSearchBundle\Source\DocumentProviderInterface;
use Pdir\SiteSearchBundle\Source\RecordProviderInterface;
use Pdir\SiteSearchBundle\Source\SourceRegistry;

/**
 * Writes the documents of the providers to the index.
 */
class SourceIndexer
{
    private const BATCH_SIZE = 250;

    /**
     * Fields set by the bundle, a provider cannot use them for own values.
     */
    private const RESERVED_FIELDS = ['url', 'source', 'domain', 'title', 'description', 'content', 'image', 'language', 'protected', 'groups', 'record', 'id'];

    public function __construct(
        private readonly SearchEngineInterface $engine,
        private readonly SourceRegistry $registry,
    ) {
    }

    /**
     * Indexes all documents of a provider and removes documents of this source that
     * the provider no longer returns.
     *
     * @param (callable(int $indexed): void)|null $progress
     *
     * @return array{indexed: int, removed: int, errors: array<string, string>}
     */
    public function reindex(DocumentProviderInterface $provider, callable|null $progress = null): array
    {
        $source = $provider->getSource();
        $existing = array_fill_keys($this->engine->findUrls(Filter::source($source)), true);
        $batch = [];
        $indexed = 0;
        $errors = [];

        foreach ($provider->getDocuments() as $document) {
            try {
                $document = $this->normalize($document, $source);
            } catch (\InvalidArgumentException $e) {
                $errors[(string) ($document['url'] ?? '#'.\count($errors))] = $e->getMessage();
                continue;
            }

            $batch[$document['url']] = $document;

            if (\count($batch) >= self::BATCH_SIZE) {
                $indexed += $this->flush($batch, $existing, $errors);
                $batch = [];

                if (null !== $progress) {
                    $progress($indexed);
                }
            }
        }

        $indexed += $this->flush($batch, $existing, $errors);

        if (null !== $progress) {
            $progress($indexed);
        }

        // Everything still in $existing was not returned by the provider anymore
        $removed = array_keys($existing);

        if ([] !== $removed) {
            $this->engine->deleteDocuments($removed);
        }

        return ['indexed' => $indexed, 'removed' => \count($removed), 'errors' => $errors];
    }

    /**
     * Updates the document of a single record (after saving it in the back end).
     */
    public function updateRecord(RecordProviderInterface $provider, int $id): void
    {
        $source = $provider->getSource();
        $document = $provider->getDocument($id);

        $stale = $this->engine->findUrls($this->recordFilter($source, $id));

        if (null !== $document) {
            $document = $this->normalize(['id' => $id, ...$document], $source);
            $errors = $this->engine->addDocuments([$document]);

            if ([] !== $errors) {
                throw new \RuntimeException(\sprintf('Could not index %s: %s', $document['url'], implode(' ', $errors)));
            }

            // The URL changed (e.g. new alias): remove the old document
            $stale = array_values(array_diff($stale, [$document['url']]));
        }

        if ([] !== $stale) {
            $this->engine->deleteDocuments($stale);
        }
    }

    public function removeRecord(string $source, int|string $id): void
    {
        $urls = $this->engine->findUrls($this->recordFilter($source, $id));

        if ([] !== $urls) {
            $this->engine->deleteDocuments($urls);
        }
    }

    /**
     * Removes all documents of a source.
     */
    public function removeSource(string $source): int
    {
        if (!SourceRegistry::isValidName($source)) {
            throw new \InvalidArgumentException(\sprintf('Invalid source name "%s".', $source));
        }

        $urls = $this->engine->findUrls(Filter::source($source));

        if ([] !== $urls) {
            $this->engine->deleteDocuments($urls);
        }

        return \count($urls);
    }

    /**
     * Number of documents per source (crawled pages and all providers).
     *
     * @return array<string, int>
     */
    public function countBySource(): array
    {
        $counts = [];

        foreach ([SourceRegistry::PAGE_SOURCE, ...array_keys($this->registry->all())] as $source) {
            $counts[$source] = $this->engine->count(Filter::source($source));
        }

        return $counts;
    }

    /**
     * Brings a provider document into the format of the index.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    public function normalize(array $document, string $source): array
    {
        $url = trim((string) ($document['url'] ?? ''));
        $host = parse_url($url, PHP_URL_HOST);

        if (!preg_match('#^https?://#i', $url) || !\is_string($host) || '' === $host) {
            throw new \InvalidArgumentException(\sprintf('The document URL "%s" of source "%s" must be absolute.', $url, $source));
        }

        $title = $this->toText($document['title'] ?? '');

        if ('' === $title) {
            throw new \InvalidArgumentException(\sprintf('The document "%s" has no title.', $url));
        }

        $record = '';

        if (isset($document['id']) && '' !== (string) $document['id']) {
            $record = $this->recordKey($source, $document['id']);
        }

        $normalized = [
            'url' => $url,
            'source' => $source,
            'domain' => strtolower(trim((string) ($document['domain'] ?? '')) ?: $host),
            'title' => $title,
            'description' => $this->toText($document['description'] ?? ''),
            'content' => mb_substr($this->toText($document['content'] ?? ''), 0, SiteSearchIndexer::MAX_CONTENT_LENGTH),
            'image' => preg_match('#^https?://#i', (string) ($document['image'] ?? '')) ? (string) $document['image'] : '',
            'language' => preg_match('/^([a-z]{2})(?:[-_]|$)/i', (string) ($document['language'] ?? ''), $matches) ? strtolower($matches[1]) : '',
            'record' => $record,
            ...IndexSchema::normalizeAccessFields((bool) ($document['protected'] ?? false), (array) ($document['groups'] ?? [])),
        ];

        // Additional values (e.g. "category") are stored and returned with the hit
        foreach ($document as $key => $value) {
            if (\in_array($key, self::RESERVED_FIELDS, true) || null === $value || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $key)) {
                continue;
            }

            if (\is_scalar($value)) {
                $normalized[$key] = $value;
            } elseif (\is_array($value) && array_is_list($value) && array_filter($value, 'is_scalar') === $value) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, array<string, mixed>> $batch
     * @param array<string, true>                 $existing
     * @param array<string, string>               $errors
     */
    private function flush(array $batch, array &$existing, array &$errors): int
    {
        if ([] === $batch) {
            return 0;
        }

        $batchErrors = $this->engine->addDocuments(array_values($batch));

        foreach (array_keys($batch) as $url) {
            unset($existing[$url]);
        }

        $errors = [...$errors, ...$batchErrors];

        return \count($batch) - \count($batchErrors);
    }

    private function recordFilter(string $source, int|string $id): Filter
    {
        return Filter::record($this->recordKey($source, $id));
    }

    private function recordKey(string $source, mixed $id): string
    {
        $id = (string) $id;

        if (!SourceRegistry::isValidName($source) || !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', $id)) {
            throw new \InvalidArgumentException(\sprintf('Invalid record ID "%s" of source "%s".', $id, $source));
        }

        return $source.':'.$id;
    }

    private function toText(mixed $value): string
    {
        $text = (string) (\is_scalar($value) ? $value : '');

        if (str_contains($text, '<')) {
            // Separate the text of adjacent block elements
            $text = strip_tags(str_replace('<', ' <', $text));
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00AD}", '', $text)));
    }
}
