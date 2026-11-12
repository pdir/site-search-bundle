<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Engine;

use Pdir\SiteSearchBundle\Source\SourceRegistry;

/**
 * Engine-independent filter. Values are validated here, so the engines can put
 * them into their filter syntax without escaping; invalid values are dropped.
 */
final class Filter
{
    /**
     * @param list<string>   $sources      Any of these sources (empty = all)
     * @param list<int>|null $memberGroups null = no access restriction (internal use),
     *                                     [] = guests: only unprotected documents,
     *                                     [1, 2] = unprotected or one of these groups
     */
    private function __construct(
        public readonly string|null $domain,
        public readonly string|null $language,
        public readonly array $sources,
        public readonly array|null $memberGroups,
        public readonly string|null $record,
    ) {
    }

    /**
     * @param list<string>     $sources
     * @param list<mixed>|null $memberGroups
     */
    public static function create(string|null $domain = null, string|null $language = null, array $sources = [], array|null $memberGroups = null, string|null $record = null): self
    {
        $domain = strtolower(trim((string) $domain));
        $language = strtolower(trim((string) $language));
        $record = (string) $record;

        return new self(
            preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $domain) ? $domain : null,
            preg_match('/^[a-z]{2}$/', $language) ? $language : null,
            array_values(array_unique(array_filter(array_map('strval', $sources), SourceRegistry::isValidName(...)))),
            null === $memberGroups ? null : array_values(array_unique(array_filter(array_map('intval', $memberGroups), static fn (int $id): bool => $id > 0))),
            preg_match('/^[a-z0-9][a-z0-9_-]{0,63}:[A-Za-z0-9_.:-]{1,128}$/', $record) ? $record : null,
        );
    }

    public static function all(): self
    {
        return new self(null, null, [], null, null);
    }

    public static function source(string $source): self
    {
        return self::create(sources: [$source]);
    }

    public static function record(string $record): self
    {
        return self::create(record: $record);
    }

    /**
     * Shared formatter: the engines differ only in the syntax of the conditions.
     *
     * @param callable(string $field, string|int $value): string $equals
     * @param callable(list<string> $parts): string              $or
     * @param callable(list<string> $parts): string              $and
     */
    public function format(callable $equals, callable $or, callable $and): string
    {
        $conditions = [];

        if (null !== $this->domain) {
            $conditions[] = $equals('domain', $this->domain);
        }

        if (null !== $this->language) {
            $conditions[] = $equals('language', $this->language);
        }

        if ([] !== $this->sources) {
            $parts = array_map(static fn (string $source): string => $equals('source', $source), $this->sources);
            $conditions[] = 1 === \count($parts) ? $parts[0] : $or($parts);
        }

        if (null !== $this->record) {
            $conditions[] = $equals('record', $this->record);
        }

        if (null !== $this->memberGroups) {
            if ([] === $this->memberGroups) {
                $conditions[] = $equals('protected', 0);
            } else {
                // "groups = 1 OR groups = 2" instead of IN: IN does not match array attributes
                // in Loupe 0.x; works the same in the other engines
                $conditions[] = $or([$equals('protected', 0), ...array_map(static fn (int $id): string => $equals('groups', $id), $this->memberGroups)]);
            }
        }

        return [] === $conditions ? '' : $and($conditions);
    }
}
