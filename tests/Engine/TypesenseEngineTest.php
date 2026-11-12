<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Tests\Engine;

use Symfony\Component\HttpClient\HttpClient;

class TypesenseEngineTest extends EngineContractTestCase
{
    use ExternalEngineTestTrait;

    private static function envName(): string
    {
        return 'PDIR_SITE_SEARCH_TEST_TYPESENSE';
    }

    private function deleteIndex(string $dsn, string $name): void
    {
        [$baseUrl, $headers] = self::server($dsn, 'X-TYPESENSE-API-KEY', '');

        HttpClient::create()->request('DELETE', $baseUrl.'/collections/'.$name, ['headers' => $headers])->getStatusCode();
    }
}
