<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Tests\Engine;

use Pdir\SiteSearchBundle\Engine\EngineFactory;
use Pdir\SiteSearchBundle\Engine\SearchEngineInterface;
use Pdir\SiteSearchBundle\Search\LoupeIndexFactory;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Runs the contract tests against a real server if the DSN is set in the
 * environment, e.g.
 * PDIR_SITE_SEARCH_TEST_MEILISEARCH=meilisearch://key@127.0.0.1:7700 (see
 * .github/workflows/ci.yml). Each test uses its own index.
 */
trait ExternalEngineTestTrait
{
    private string|null $indexName = null;

    private string|null $dsn = null;

    protected function tearDown(): void
    {
        // Skipped tests (no DSN set) have not created an index
        if (null === $this->dsn || null === $this->indexName) {
            return;
        }

        $this->deleteIndex($this->dsn, $this->indexName);
    }

    abstract private static function envName(): string;

    abstract private function deleteIndex(string $dsn, string $name): void;

    protected function createEngine(): SearchEngineInterface
    {
        $dsn = getenv(self::envName());

        if (!\is_string($dsn) || '' === $dsn) {
            $this->markTestSkipped(\sprintf('Set %s to run the tests against a real server.', self::envName()));
        }

        $this->indexName = 'pdir_test_'.bin2hex(random_bytes(4));
        $this->dsn = $dsn;

        return (new EngineFactory(new LoupeIndexFactory(sys_get_temp_dir().'/unused'), HttpClient::create()))
            ->create($dsn.(str_contains($dsn, '?') ? '&' : '?').'index='.$this->indexName.'&collection='.$this->indexName)
        ;
    }

    /**
     * @return array{string, array<string, string>}
     */
    private static function server(string $dsn, string $keyHeader, string $keyPrefix): array
    {
        $parts = parse_url($dsn);

        return [
            'http://'.$parts['host'].':'.$parts['port'],
            [$keyHeader => $keyPrefix.rawurldecode((string) ($parts['user'] ?? ''))],
        ];
    }
}
