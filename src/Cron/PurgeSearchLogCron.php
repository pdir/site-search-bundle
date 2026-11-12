<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Pdir\SiteSearchBundle\Log\SearchLog;

/**
 * Deletes log entries older than pdir_site_search.log.retention_days.
 */
#[AsCronJob('daily')]
class PurgeSearchLogCron
{
    public function __construct(private readonly SearchLog $searchLog)
    {
    }

    public function __invoke(): void
    {
        $this->searchLog->purge();
    }
}
