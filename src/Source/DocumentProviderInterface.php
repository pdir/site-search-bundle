<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Source;

/**
 * Supplies documents for the site search index that are not (only) found by the
 * Contao crawler, e.g. news, companies or products.
 *
 * Services implementing this interface are registered automatically (tag
 * "pdir_site_search.document_provider").
 *
 * A document is an array with these keys:
 *  - url (required): absolute URL, primary key of the index; documents of the
 *    crawler with the same URL are replaced, not duplicated
 *  - title (required), description, content (plain text or HTML), image (absolute URL)
 *  - language ("de", "de-DE"), domain (default: host of the URL)
 *  - protected (bool), groups (list of member group IDs)
 *  - id: ID of the record (needed for updates via RecordProviderInterface)
 *  - any further scalar values (e.g. "category", "date"), returned with the hit
 */
interface DocumentProviderInterface
{
    /**
     * Name of the source, stored in the "source" field (lowercase letters, digits,
     * "_" and "-"; "page" is reserved for the crawler).
     */
    public function getSource(): string;

    /**
     * All documents of this source. Use a generator for large data sets.
     *
     * @return iterable<array<string, mixed>>
     */
    public function getDocuments(): iterable;
}
