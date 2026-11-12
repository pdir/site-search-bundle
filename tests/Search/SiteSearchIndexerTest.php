<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Tests\Search;

use Contao\CoreBundle\Search\Document;
use Contao\CoreBundle\Search\Indexer\IndexerException;
use Nyholm\Psr7\Uri;
use Pdir\SiteSearchBundle\Engine\LoupeEngine;
use Pdir\SiteSearchBundle\Search\LoupeIndexFactory;
use Pdir\SiteSearchBundle\Search\SiteSearch;
use Pdir\SiteSearchBundle\Search\SiteSearchIndexer;
use PHPUnit\Framework\TestCase;

class SiteSearchIndexerTest extends TestCase
{
    private string $indexPath;

    protected function setUp(): void
    {
        $this->indexPath = sys_get_temp_dir().'/pdir-site-search-indexer-test-'.bin2hex(random_bytes(4)).'/index';
    }

    protected function tearDown(): void
    {
        (new LoupeIndexFactory($this->indexPath))->drop();
        @rmdir(\dirname($this->indexPath));
    }

    public function testMapsPageToIndexDocument(): void
    {
        $data = $this->indexer()->createIndexDocument($this->page());

        $this->assertSame('https://www.example.com/de/wartung.html', $data['url']);
        $this->assertSame('www.example.com', $data['domain']);
        $this->assertSame('page', $data['source']);
        $this->assertSame('Wartung', $data['title']);
        $this->assertSame('Alles zur Wartung', $data['description']);
        $this->assertSame('https://www.example.com/files/wartung.jpg', $data['image']);
        $this->assertSame('de', $data['language']);
        $this->assertSame(0, $data['protected']);
        $this->assertSame([0], $data['groups']);
        $this->assertArrayNotHasKey('meta', $data);
        $this->assertArrayNotHasKey('tags', $data);
    }

    public function testExtractsVisibleBodyText(): void
    {
        $content = $this->indexer()->createIndexDocument($this->page())['content'];

        $this->assertSame('Wartung Erster Absatz Zweiter Absatz mit Wartungsvertrag & mehr', $content);
    }

    public function testFallsBackToHtmlTitle(): void
    {
        $data = $this->indexer()->createIndexDocument($this->page(['title' => '']));

        $this->assertSame('Wartung - Beispiel GmbH', $data['title']);
    }

    public function testIgnoresRelativeImages(): void
    {
        $this->assertSame('', $this->indexer()->createIndexDocument($this->page([], '<meta property="og:image" content="files/a.jpg">'))['image']);
    }

    public function testSkipsNoSearchPages(): void
    {
        $this->expectException(IndexerException::class);

        $this->indexer()->createIndexDocument($this->page(['noSearch' => true]));
    }

    public function testSkipsPagesWithoutJsonLd(): void
    {
        $this->expectException(IndexerException::class);

        $this->indexer()->createIndexDocument(new Document(new Uri('https://www.example.com/'), 200, [], '<html><body>Text</body></html>'));
    }

    public function testDoesNotFailWithoutHeadElement(): void
    {
        $this->assertSame('just text without html', $this->indexer()->extractContent('just text without html'));
        $this->assertSame('Text', $this->indexer()->extractContent('<body><p>Text</p>'));
    }

    public function testSkipsProtectedPagesByDefault(): void
    {
        $this->expectException(IndexerException::class);
        $this->expectExceptionMessage('protected');

        $this->indexer()->createIndexDocument($this->page(['protected' => true, 'groups' => [3]]));
    }

    public function testIndexesProtectedPagesIfEnabled(): void
    {
        $data = $this->indexer(true)->createIndexDocument($this->page(['protected' => true, 'groups' => [3, '4']]));

        $this->assertSame(1, $data['protected']);
        $this->assertSame([3, 4], $data['groups']);
    }

    public function testIndexSearchDeleteAndClear(): void
    {
        $factory = new LoupeIndexFactory($this->indexPath);
        $indexer = new SiteSearchIndexer(new LoupeEngine($factory), true);
        $search = new SiteSearch(new LoupeEngine($factory));

        $indexer->index($this->page());
        $indexer->index($this->page(['protected' => true, 'groups' => [3]], '', 'https://www.example.com/de/intern.html'));

        $this->assertSame(['https://www.example.com/de/wartung.html'], $this->urls($search->search('Wartungsvertrag', 'de', [], 1, 10, 'www.example.com')));
        $this->assertCount(2, $search->search('Wartungsvertrag', 'de', [3])['hits']);

        $indexer->delete($this->page());
        $this->assertSame(['https://www.example.com/de/intern.html'], $this->urls($search->search('Wartungsvertrag', 'de', [3])));

        $indexer->clear();
        $this->assertSame([], $search->search('Wartungsvertrag', 'de', [3])['hits']);

        // The index can be used again after clearing
        $indexer->index($this->page());
        $this->assertCount(1, $search->search('Wartungsvertrag', 'de')['hits']);
    }

    public function testSkipsPagesIndexedByAProvider(): void
    {
        $factory = new LoupeIndexFactory($this->indexPath);
        $factory->addDocuments([[
            'url' => 'https://www.example.com/de/wartung.html',
            'source' => 'news',
            'domain' => 'www.example.com',
            'title' => 'Wartung (News)',
            'description' => '',
            'content' => 'Wartungsvertrag',
            'image' => '',
            'language' => 'de',
            'record' => 'news:1',
            ...LoupeIndexFactory::normalizeAccessFields(false, []),
        ]]);

        try {
            (new SiteSearchIndexer(new LoupeEngine($factory)))->index($this->page());
            $this->fail('Expected an IndexerException');
        } catch (IndexerException $e) {
            $this->assertTrue($e->isOnlyWarning());
            $this->assertStringContainsString('"news"', $e->getMessage());
        }

        $this->assertSame('Wartung (News)', $factory->create()->getDocument('https://www.example.com/de/wartung.html')['title']);
    }

    private function indexer(bool $indexProtected = false): SiteSearchIndexer
    {
        return new SiteSearchIndexer(new LoupeEngine(new LoupeIndexFactory($this->indexPath)), $indexProtected);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function page(array $meta = [], string $head = '', string $url = 'https://www.example.com/de/wartung.html'): Document
    {
        $jsonLd = json_encode([
            '@context' => ['contao' => 'https://schema.contao.org/'],
            '@type' => 'contao:Page',
            'contao:title' => $meta['title'] ?? 'Wartung',
            'contao:pageId' => 42,
            'contao:noSearch' => $meta['noSearch'] ?? false,
            'contao:protected' => $meta['protected'] ?? false,
            'contao:groups' => $meta['groups'] ?? [],
            'contao:fePreview' => false,
        ]);

        $head = $head ?: '<meta name="description" content="Alles zur Wartung"><meta property="og:image" content="/files/wartung.jpg">';

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="de-DE">
            <head>
                <meta charset="UTF-8">
                <title>Wartung - Beispiel GmbH</title>
                $head
                <style>body { color: red; }</style>
                <script type="application/ld+json">$jsonLd</script>
            </head>
            <body>
                <!-- indexer::stop --><nav>Navigation Startseite</nav><!-- indexer::continue -->
                <h1>Wartung</h1><p>Erster&nbsp;Absatz</p><p>Zweiter Absatz mit War&shy;tungs&shy;vertrag &amp; mehr</p>
                <script>var x = "Skript";</script><noscript>Bitte JS aktivieren</noscript>
                <!-- indexer::stop --><footer>Impressum</footer><!-- indexer::continue -->
            </body>
            </html>
            HTML;

        return new Document(new Uri($url), 200, ['content-type' => ['text/html; charset=UTF-8']], $html);
    }

    /**
     * @param array{hits: list<array<string, mixed>>} $result
     *
     * @return list<string>
     */
    private function urls(array $result): array
    {
        return array_map(static fn (array $hit): string => $hit['url'], $result['hits']);
    }
}
