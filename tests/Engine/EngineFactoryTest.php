<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Tests\Engine;

use Pdir\SiteSearchBundle\Engine\EngineFactory;
use Pdir\SiteSearchBundle\Engine\LoupeEngine;
use Pdir\SiteSearchBundle\Engine\MeilisearchEngine;
use Pdir\SiteSearchBundle\Engine\TypesenseEngine;
use Pdir\SiteSearchBundle\Search\LoupeIndexFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class EngineFactoryTest extends TestCase
{
    public function testLoupeIsTheDefault(): void
    {
        $this->assertInstanceOf(LoupeEngine::class, $this->factory()->create(''));
        $this->assertInstanceOf(LoupeEngine::class, $this->factory()->create('loupe://'));
        $this->assertInstanceOf(LoupeEngine::class, $this->factory()->create(' LOUPE:// '));
    }

    /**
     * @return iterable<string, array{string, class-string, string, array<string, string>}>
     */
    public static function externalProvider(): iterable
    {
        yield 'meilisearch key in user part' => ['meilisearch://s%40cret@search.local', MeilisearchEngine::class, 'http://search.local:7700/indexes/pdir_site_search/stats', ['authorization' => 'Bearer s@cret']];
        yield 'meilisearch key as query, tls, port, index' => ['meilisearch://search.local:443?key=abc&tls=1&index=my-site', MeilisearchEngine::class, 'https://search.local/indexes/my-site/stats', ['authorization' => 'Bearer abc']];
        yield 'meilisearch with path prefix' => ['meilisearch://abc@proxy.local:80/meili/', MeilisearchEngine::class, 'http://proxy.local/meili/indexes/pdir_site_search/stats', ['authorization' => 'Bearer abc']];
        yield 'typesense' => ['typesense://xyz@127.0.0.1', TypesenseEngine::class, 'http://127.0.0.1:8108/collections/pdir_site_search', ['x-typesense-api-key' => 'xyz']];
        yield 'typesense collection' => ['typesense://127.0.0.1:9000?key=xyz&collection=site_1', TypesenseEngine::class, 'http://127.0.0.1:9000/collections/site_1', ['x-typesense-api-key' => 'xyz']];
    }

    /**
     * @param class-string          $class
     * @param array<string, string> $headers
     */
    #[DataProvider('externalProvider')]
    public function testCreatesExternalEngines(string $dsn, string $class, string $expectedUrl, array $headers): void
    {
        $requests = [];

        $client = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = [$url, $options['normalized_headers']];

                return new MockResponse(str_contains($url, '/stats') ? '{"numberOfDocuments": 3}' : '{"name": "x", "num_documents": 3}');
            },
        );

        $engine = $this->factory($client)->create($dsn);
        $this->assertInstanceOf($class, $engine);

        $info = $engine->getInfo();
        $this->assertSame(3, $info['documents']);
        $this->assertStringNotContainsString('abc', $info['location'].$info['engine'], 'The API key is never shown');
        $this->assertStringNotContainsString('xyz', $info['location'].$info['engine']);

        $this->assertSame($expectedUrl, $requests[0][0]);

        foreach ($headers as $name => $value) {
            $this->assertSame([strtolower($name.': '.$value)], array_map(strtolower(...), $requests[0][1][$name]));
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'unknown scheme' => ['elasticsearch://secret@localhost:9200', 'Unsupported search engine "elasticsearch"'];
        yield 'no host' => ['meilisearch://', 'Invalid search engine DSN'];
        yield 'invalid index name' => ['meilisearch://localhost?index=../../keys', 'Invalid index name'];
        yield 'invalid collection name' => ['typesense://localhost?collection=a%20b', 'Invalid index name'];
    }

    #[DataProvider('invalidProvider')]
    public function testRejectsInvalidDsns(string $dsn, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->factory()->create($dsn);
    }

    public function testDoesNotLeakTheKeyInErrors(): void
    {
        try {
            $this->factory()->create('meilisearch://topsecret@:7700');
            $this->fail('Expected an exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString('topsecret', $e->getMessage());
        }
    }

    private function factory(MockHttpClient|null $client = null): EngineFactory
    {
        return new EngineFactory(new LoupeIndexFactory(sys_get_temp_dir().'/pdir-site-search-unused'), $client ?? new MockHttpClient());
    }
}
