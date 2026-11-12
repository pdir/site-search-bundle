<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Source;

/**
 * A provider whose documents belong to the records of a Contao table.
 *
 * The bundle updates the index when a record is saved, toggled or deleted in the
 * back end (onsubmit/ondelete callbacks of the table).
 */
interface RecordProviderInterface extends DocumentProviderInterface
{
    /**
     * Name of the DCA table, e.g. "tl_news".
     */
    public function getTable(): string;

    /**
     * The document of a record, or null if it must not be in the index (e.g. not
     * published). The returned document must contain the "id".
     *
     * @return array<string, mixed>|null
     */
    public function getDocument(int $id): array|null;
}
