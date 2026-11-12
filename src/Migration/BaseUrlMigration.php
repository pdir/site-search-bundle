<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * Earlier versions stored tl_module.siteSearchBaseUrl as TEXT NULL. The column is now
 * VARCHAR(255) NOT NULL: MySQL/MariaDB in strict mode refuse the schema update while
 * the column contains NULL or longer values ("Data truncated"), so they are cleaned up
 * first. A base URL longer than 255 characters is not valid anyway.
 */
class BaseUrlMigration extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getName(): string
    {
        return 'pdir/site-search-bundle: clean up siteSearchBaseUrl';
    }

    public function shouldRun(): bool
    {
        $schemaManager = $this->connection->createSchemaManager();

        if (!$schemaManager->tablesExist(['tl_module'])) {
            return false;
        }

        $columns = $schemaManager->listTableColumns('tl_module');

        if (!isset($columns['sitesearchbaseurl'])) {
            return false;
        }

        return (bool) $this->connection->fetchOne('SELECT 1 FROM tl_module WHERE '.$this->condition().' LIMIT 1');
    }

    public function run(): MigrationResult
    {
        $rows = $this->connection->executeStatement("UPDATE tl_module SET siteSearchBaseUrl = '' WHERE ".$this->condition());

        return $this->createResult(true, \sprintf('Cleaned up siteSearchBaseUrl in %d front end modules.', $rows));
    }

    private function condition(): string
    {
        return 'siteSearchBaseUrl IS NULL OR '.$this->connection->getDatabasePlatform()->getLengthExpression('siteSearchBaseUrl').' > 255';
    }
}
