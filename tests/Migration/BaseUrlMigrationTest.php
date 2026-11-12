<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Pdir\SiteSearchBundle\Migration\BaseUrlMigration;
use PHPUnit\Framework\TestCase;

class BaseUrlMigrationTest extends TestCase
{
    public function testCleansUpNullAndTooLongValues(): void
    {
        $connection = $this->createConnection('TEXT NULL');
        $connection->insert('tl_module', ['id' => 1, 'siteSearchBaseUrl' => null]);
        $connection->insert('tl_module', ['id' => 2, 'siteSearchBaseUrl' => 'https://example.com/search/results']);
        $connection->insert('tl_module', ['id' => 3, 'siteSearchBaseUrl' => 'https://example.com/'.str_repeat('a', 300)]);

        $migration = new BaseUrlMigration($connection);

        $this->assertTrue($migration->shouldRun());
        $this->assertTrue($migration->run()->isSuccessful());
        $this->assertSame(['', 'https://example.com/search/results', ''], $connection->fetchFirstColumn('SELECT siteSearchBaseUrl FROM tl_module ORDER BY id'));
        $this->assertFalse($migration->shouldRun());
    }

    public function testDoesNotRunWithoutTableOrColumn(): void
    {
        $this->assertFalse((new BaseUrlMigration(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])))->shouldRun());

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE tl_module (id INTEGER PRIMARY KEY, type VARCHAR(64))');

        $this->assertFalse((new BaseUrlMigration($connection))->shouldRun());

        $this->assertFalse((new BaseUrlMigration($this->createConnection("VARCHAR(255) NOT NULL DEFAULT ''")))->shouldRun());
    }

    private function createConnection(string $columnType): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement("CREATE TABLE tl_module (id INTEGER PRIMARY KEY, siteSearchBaseUrl $columnType)");

        return $connection;
    }
}
