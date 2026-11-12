<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Engine;

/**
 * Fields of the index, the same for every engine.
 *
 * Every document has: url, source, domain, title, description, content, image,
 * language, record, protected (0/1), groups (list of int, [0] = none). Providers
 * may add further scalar fields; they are stored and returned, but not searched.
 */
final class IndexSchema
{
    /**
     * Order matters: earlier attributes rank higher.
     */
    public const SEARCHABLE_ATTRIBUTES = ['title', 'description', 'content'];

    public const FILTERABLE_ATTRIBUTES = ['domain', 'groups', 'language', 'protected', 'record', 'source'];

    public const SORTABLE_ATTRIBUTES = ['title'];

    /**
     * Fields used by the engines internally, never returned with a hit.
     */
    public const INTERNAL_FIELDS = ['id', 'content', 'groups', 'protected', 'record'];

    /**
     * Returns the access fields in the format the filters expect.
     *
     * - protected as 0/1: Loupe 0.x cannot filter on booleans
     * - groups never empty ([0] = no group): Loupe 0.x derives the attribute
     *   type from the first document and silently drops later documents
     *   with a non-empty array if the first one had an empty array
     *
     * @param array<mixed> $groups
     *
     * @return array{protected: int, groups: list<int>}
     */
    public static function normalizeAccessFields(bool $protected, array $groups): array
    {
        $groups = array_values(array_unique(array_filter(array_map('intval', $groups), static fn (int $id): bool => $id > 0)));

        return [
            'protected' => (int) $protected,
            'groups' => [] === $groups ? [0] : $groups,
        ];
    }

    /**
     * ID for engines that do not accept URLs as document ID (Meilisearch allows
     * only [a-zA-Z0-9_-]).
     */
    public static function documentId(string $url): string
    {
        return sha1($url);
    }
}
