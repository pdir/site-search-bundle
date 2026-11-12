<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Tests\Search;

use Pdir\SiteSearchBundle\Engine\LoupeEngine;
use Pdir\SiteSearchBundle\Search\LoupeIndexFactory;
use Pdir\SiteSearchBundle\Search\SiteSearch;
use Pdir\SiteSearchBundle\Search\SourceIndexer;
use Pdir\SiteSearchBundle\Source\SourceRegistry;
use Pdir\SiteSearchBundle\Tests\Fixtures\CompanyProvider;
use PHPUnit\Framework\TestCase;

class SourceIndexerTest extends TestCase
{
    private string $indexPath;

    private LoupeIndexFactory $factory;

    private CompanyProvider $provider;

    private SourceIndexer $indexer;

    private SiteSearch $search;

    protected function setUp(): void
    {
        $this->indexPath = sys_get_temp_dir().'/pdir-site-search-source-test-'.bin2hex(random_bytes(4)).'/index';
        $this->factory = new LoupeIndexFactory($this->indexPath);
        $this->provider = new CompanyProvider();
        $this->indexer = new SourceIndexer(new LoupeEngine($this->factory), new SourceRegistry([$this->provider]));
        $this->search = new SiteSearch(new LoupeEngine($this->factory));

        // A crawled page, must not be touched by the provider
        $this->factory->addDocuments([[
            'url' => 'https://www.example.com/de/heizung.html',
            'source' => 'page',
            'domain' => 'www.example.com',
            'title' => 'Heizung',
            'description' => '',
            'content' => 'Ratgeber Heizungsbau',
            'image' => '',
            'language' => 'de',
            'record' => '',
            ...LoupeIndexFactory::normalizeAccessFields(false, []),
        ]]);
    }

    protected function tearDown(): void
    {
        $this->factory->drop();

        @rmdir(\dirname($this->indexPath));
    }

    public function testReindexAddsDocumentsOfTheProvider(): void
    {
        $result = $this->indexer->reindex($this->provider);

        $this->assertSame(['indexed' => 3, 'removed' => 0, 'errors' => []], $result);

        $hits = $this->search->search('Heizungsbau', 'de')['hits'];

        $this->assertSame(['page', 'company', 'company'], array_column($hits, 'source'));
    }

    public function testFiltersBySource(): void
    {
        $this->indexer->reindex($this->provider);

        $this->assertSame(['company', 'company'], array_column($this->search->search('Heizungsbau', 'de', [], 1, 10, null, ['company'])['hits'], 'source'));
        $this->assertSame(['page'], array_column($this->search->search('Heizungsbau', 'de', [], 1, 10, null, ['page'])['hits'], 'source'));
        $this->assertCount(3, $this->search->search('Heizungsbau', 'de', [], 1, 10, null, ['page', 'company'])['hits']);
    }

    public function testInvalidSourceInFilterIsIgnored(): void
    {
        $this->assertSame('protected = 0', LoupeEngine::formatFilter($this->search->buildFilter(null, [], null, ["x' OR 1=1"])));
        $this->assertSame("(source = 'page' OR source = 'news') AND protected = 0", LoupeEngine::formatFilter($this->search->buildFilter(null, [], null, ['page', 'news', 'page'])));
    }

    public function testAdditionalFieldsAreReturnedButInternalFieldsAreNot(): void
    {
        $this->indexer->reindex($this->provider);

        $hit = $this->search->search('Müller', 'de')['hits'][0];

        $this->assertSame('Heizung & Sanitär', $hit['category']);
        $this->assertSame('https://mueller.example', $hit['website']);
        $this->assertArrayNotHasKey('record', $hit);
        $this->assertArrayNotHasKey('groups', $hit);
        $this->assertArrayNotHasKey('protected', $hit);
        $this->assertArrayNotHasKey('content', $hit);
    }

    public function testHtmlIsConvertedToText(): void
    {
        $this->indexer->reindex($this->provider);

        $document = $this->factory->create()->getDocument('https://www.example.com/de/firmen.html#company-1');

        $this->assertSame('Müller GmbH', $document['title']);
        $this->assertSame('Heizungsbau & Sanitär seit 1950 Notdienst', $document['content']);
    }

    public function testReindexRemovesDocumentsTheProviderNoLongerReturns(): void
    {
        $this->indexer->reindex($this->provider);

        unset($this->provider->companies[2]);

        $result = $this->indexer->reindex($this->provider);

        $this->assertSame(1, $result['removed']);
        $this->assertNull($this->factory->create()->getDocument('https://www.example.com/de/firmen.html#company-2'));
        $this->assertNotNull($this->factory->create()->getDocument('https://www.example.com/de/heizung.html'));
    }

    public function testInvalidDocumentsAreReportedAndSkipped(): void
    {
        $this->provider->companies[4] = ['name' => 'Ohne URL', 'url' => '/relativ'];
        $this->provider->companies[5] = ['name' => '', 'url' => 'https://www.example.com/de/firmen.html#company-5'];

        $result = $this->indexer->reindex($this->provider);

        $this->assertSame(3, $result['indexed']);
        $this->assertCount(2, $result['errors']);
    }

    public function testUpdateRecordReplacesTheDocumentIfTheUrlChanges(): void
    {
        $this->indexer->reindex($this->provider);

        $this->provider->companies[1]['url'] = 'https://www.example.com/de/firmen/mueller.html';
        $this->indexer->updateRecord($this->provider, 1);

        $this->assertNull($this->factory->create()->getDocument('https://www.example.com/de/firmen.html#company-1'));
        $this->assertSame('Müller GmbH', $this->factory->create()->getDocument('https://www.example.com/de/firmen/mueller.html')['title']);
    }

    public function testUpdateRecordRemovesUnpublishedRecords(): void
    {
        $this->indexer->reindex($this->provider);

        $this->provider->companies[1]['published'] = false;
        $this->indexer->updateRecord($this->provider, 1);

        $this->assertNull($this->factory->create()->getDocument('https://www.example.com/de/firmen.html#company-1'));
        $this->assertNotNull($this->factory->create()->getDocument('https://www.example.com/de/firmen.html#company-2'));
    }

    public function testRemoveRecordAndSource(): void
    {
        $this->indexer->reindex($this->provider);

        $this->indexer->removeRecord('company', 2);

        $this->assertNull($this->factory->create()->getDocument('https://www.example.com/de/firmen.html#company-2'));

        $this->assertSame(2, $this->indexer->removeSource('company'));
        $this->assertSame(1, $this->factory->create()->countDocuments());
    }

    public function testProtectedRecordsAreOnlyShownToGroupMembers(): void
    {
        $this->indexer->reindex($this->provider);

        $this->assertSame([], $this->search->search('Intern', 'de')['hits']);
        $this->assertCount(1, $this->search->search('Intern', 'de', [7])['hits']);
    }
}
