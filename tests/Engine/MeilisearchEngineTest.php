<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Tests\Engine;

use Symfony\Component\HttpClient\HttpClient;

class MeilisearchEngineTest extends EngineContractTestCase
{
    use ExternalEngineTestTrait;

    private static function envName(): string
    {
        return 'PDIR_SITE_SEARCH_TEST_MEILISEARCH';
    }

    private function deleteIndex(string $dsn, string $name): void
    {
        [$baseUrl, $headers] = self::server($dsn, 'Authorization', 'Bearer ');

        HttpClient::create()->request('DELETE', $baseUrl.'/indexes/'.$name, ['headers' => $headers])->getStatusCode();
    }
}
