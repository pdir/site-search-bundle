<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Tests\Source;

use Pdir\SiteSearchBundle\Source\DocumentProviderInterface;
use Pdir\SiteSearchBundle\Source\SourceRegistry;
use Pdir\SiteSearchBundle\Tests\Fixtures\CompanyProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SourceRegistryTest extends TestCase
{
    public function testRegistersProvidersBySource(): void
    {
        $registry = new SourceRegistry([$provider = new CompanyProvider()]);

        $this->assertTrue($registry->has('company'));
        $this->assertSame($provider, $registry->get('company'));
        $this->assertSame([$provider], $registry->getRecordProviders('tl_company'));
        $this->assertSame([], $registry->getRecordProviders('tl_news'));
    }

    #[DataProvider('invalidNames')]
    public function testRejectsInvalidSourceNames(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SourceRegistry([$this->provider($name)]);
    }

    /**
     * @return iterable<array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'reserved' => ['page'];
        yield 'quote' => ["news' OR 1=1"];
        yield 'uppercase' => ['News'];
        yield 'empty' => [''];
    }

    public function testRejectsDuplicateSources(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SourceRegistry([$this->provider('news'), $this->provider('news')]);
    }

    private function provider(string $source): DocumentProviderInterface
    {
        return new class($source) implements DocumentProviderInterface {
            public function __construct(private readonly string $source)
            {
            }

            public function getSource(): string
            {
                return $this->source;
            }

            public function getDocuments(): iterable
            {
                return [];
            }
        };
    }
}
