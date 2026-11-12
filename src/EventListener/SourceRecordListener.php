<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\DataContainer;
use Pdir\SiteSearchBundle\Search\SourceIndexer;
use Pdir\SiteSearchBundle\Source\SourceRegistry;
use Psr\Log\LoggerInterface;

/**
 * Keeps the index up to date when records of a provider table (e.g. tl_news) are
 * saved, toggled or deleted in the back end.
 */
#[AsHook('loadDataContainer')]
class SourceRecordListener
{
    public function __construct(
        private readonly SourceRegistry $registry,
        private readonly SourceIndexer $sourceIndexer,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    public function __invoke(string $table): void
    {
        if ([] === $this->registry->getRecordProviders($table)) {
            return;
        }

        // Toggling ("published") also triggers the onsubmit callbacks
        $GLOBALS['TL_DCA'][$table]['config']['onsubmit_callback'][] = function (DataContainer $dc) use ($table): void {
            $this->onSubmit($table, (int) $dc->id);
        };

        $GLOBALS['TL_DCA'][$table]['config']['ondelete_callback'][] = function (DataContainer $dc) use ($table): void {
            $this->onDelete($table, (int) $dc->id);
        };
    }

    public function onSubmit(string $table, int $id): void
    {
        foreach ($this->registry->getRecordProviders($table) as $provider) {
            try {
                $this->sourceIndexer->updateRecord($provider, $id);
            } catch (\Throwable $e) {
                // Saving the record must not fail because of the search index
                $this->logger?->error(\sprintf('Site search: could not update %s.%d in the index: %s', $table, $id, $e->getMessage()), ['exception' => $e]);
            }
        }
    }

    public function onDelete(string $table, int $id): void
    {
        foreach ($this->registry->getRecordProviders($table) as $provider) {
            try {
                $this->sourceIndexer->removeRecord($provider->getSource(), $id);
            } catch (\Throwable $e) {
                $this->logger?->error(\sprintf('Site search: could not remove %s.%d from the index: %s', $table, $id, $e->getMessage()), ['exception' => $e]);
            }
        }
    }
}
