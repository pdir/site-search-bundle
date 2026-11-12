<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Tests\Engine;

use Pdir\SiteSearchBundle\Engine\Filter;
use Pdir\SiteSearchBundle\Engine\IndexSchema;
use Pdir\SiteSearchBundle\Engine\SearchEngineInterface;
use Pdir\SiteSearchBundle\Search\SiteSearch;
use PHPUnit\Framework\TestCase;

/**
 * The same behaviour for every engine (Loupe, Meilisearch, Typesense).
 */
abstract class EngineContractTestCase extends TestCase
{
    protected SearchEngineInterface $engine;

    protected function setUp(): void
    {
        $this->engine = $this->createEngine();
        $this->engine->drop();

        $long = str_repeat('Füllwort ', 80).'Hier steht etwas über den Wartungsvertrag für Heizungen. '.str_repeat('Füllwort ', 80);

        $errors = $this->engine->addDocuments([
            $this->document('https://example.com/de/heizung', 'Heizung warten', 'Wir warten Ihre Heizung.', $long),
            $this->document('https://example.com/de/vertrag', 'Wartungsvertrag', 'Alles zum Wartungsvertrag', 'Inhalt der Seite'),
            $this->document('https://example.com/de/mitglieder', 'Wartungsvertrag für Mitglieder', '', 'Interner Wartungsvertrag <script>alert(1)</script>', protected: true, groups: [2]),
            $this->document('https://example.com/en/contract', 'Wartungsvertrag (English)', 'Maintenance contract', '', language: 'en'),
            $this->document('https://other.example.org/de/vertrag', 'Wartungsvertrag anderer Domain', '', ''),
            $this->document('https://example.com/de/news/1', 'Neuer Wartungsvertrag', 'News', '', source: 'news', record: 'news:1', extra: ['category' => 'Ratgeber', 'date' => 1700000000]),
        ]);

        $this->assertSame([], $errors);
    }

    abstract protected function createEngine(): SearchEngineInterface;

    public function testGetDocumentReturnsStoredFields(): void
    {
        $document = $this->engine->getDocument('https://example.com/de/news/1');

        $this->assertNotNull($document);
        $this->assertSame('Neuer Wartungsvertrag', $document['title']);
        $this->assertSame('news', $document['source']);
        $this->assertSame('Ratgeber', $document['category']);
        $this->assertSame(1700000000, $document['date']);
        $this->assertArrayNotHasKey('id', $document);
        $this->assertNull($this->engine->getDocument('https://example.com/unknown'));
    }

    public function testReplacesDocumentsWithTheSameUrl(): void
    {
        $this->engine->addDocuments([$this->document('https://example.com/de/vertrag', 'Wartungsvertrag neu', '', '')]);

        $this->assertSame('Wartungsvertrag neu', $this->engine->getDocument('https://example.com/de/vertrag')['title']);
        $this->assertSame(6, $this->engine->count(Filter::all()));
    }

    public function testFindAndCountByFilter(): void
    {
        $this->assertSame(['https://example.com/de/news/1'], $this->engine->findUrls(Filter::source('news')));
        $this->assertSame(['https://example.com/de/news/1'], $this->engine->findUrls(Filter::record('news:1')));
        $this->assertSame(5, $this->engine->count(Filter::source('page')));
        $this->assertSame(6, $this->engine->count(Filter::all()));
        $this->assertSame(0, $this->engine->count(Filter::source('company')));
    }

    public function testDeletesDocuments(): void
    {
        $this->engine->deleteDocuments(['https://example.com/de/vertrag', 'https://example.com/de/news/1', 'https://example.com/unknown']);

        $this->assertNull($this->engine->getDocument('https://example.com/de/vertrag'));
        $this->assertSame(4, $this->engine->count(Filter::all()));
    }

    public function testDropEmptiesTheIndex(): void
    {
        $this->engine->drop();

        $this->assertSame(0, $this->engine->count(Filter::all()));
        $this->assertSame([], $this->engine->search('Wartungsvertrag', Filter::all(), 1, 10, 30, '<em>', '</em>')['hits']);

        // Usable again after dropping
        $this->assertSame([], $this->engine->addDocuments([$this->document('https://example.com/de/a', 'Heizung', '', '')]));
        $this->assertSame(1, $this->engine->count(Filter::all()));
    }

    public function testSearchFiltersByLanguageDomainSourceAndGroups(): void
    {
        $search = new SiteSearch($this->engine);

        $urls = $this->urls($search->search('Wartungsvertrag', 'de', [], 1, 10, 'example.com'));
        sort($urls);
        $this->assertSame(['https://example.com/de/heizung', 'https://example.com/de/news/1', 'https://example.com/de/vertrag'], $urls);

        $this->assertContains('https://example.com/de/mitglieder', $this->urls($search->search('Wartungsvertrag', 'de', [2], 1, 10, 'example.com')));
        $this->assertNotContains('https://example.com/de/mitglieder', $this->urls($search->search('Wartungsvertrag', 'de', [5], 1, 10, 'example.com')));
        $this->assertSame(['https://other.example.org/de/vertrag'], $this->urls($search->search('Wartungsvertrag', 'de', [], 1, 10, 'other.example.org')));
        $this->assertSame(['https://example.com/en/contract'], $this->urls($search->search('Wartungsvertrag', 'en', [], 1, 10, 'example.com')));
        $this->assertSame(['https://example.com/de/news/1'], $this->urls($search->search('Wartungsvertrag', 'de', [], 1, 10, 'example.com', ['news'])));
    }

    public function testTitleMatchesRankHigherThanTextMatches(): void
    {
        $urls = $this->urls((new SiteSearch($this->engine))->search('Wartungsvertrag', 'de', [], 1, 10, 'example.com'));

        $this->assertSame('https://example.com/de/heizung', end($urls), 'Only mentions the term in the page text');
    }

    public function testHighlightsAndSnippetsAreHtmlSafe(): void
    {
        $hits = (new SiteSearch($this->engine))->search('Wartungsvertrag', 'de', [2], 1, 10, 'example.com')['hits'];
        $byUrl = array_column($hits, null, 'url');

        $this->assertStringContainsString('<em>Wartungsvertrag</em>', $byUrl['https://example.com/de/vertrag']['_formatted']['title']);

        $member = $byUrl['https://example.com/de/mitglieder']['_formatted']['description'];
        $this->assertStringNotContainsString('<script>', $member);
        $this->assertStringContainsString('&lt;script&gt;', $member);
        $this->assertStringNotContainsString('&amp;lt;', $member);

        // Long page text: a short snippet around the match, never the full text
        $snippet = $byUrl['https://example.com/de/heizung']['description'];
        $this->assertStringContainsString('Wartungsvertrag', $snippet);
        $this->assertLessThan(400, mb_strlen($snippet));

        foreach (IndexSchema::INTERNAL_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $byUrl['https://example.com/de/heizung']);
        }

        $this->assertSame('Ratgeber', $byUrl['https://example.com/de/news/1']['category']);
    }

    public function testToleratesTypos(): void
    {
        $this->assertContains('https://example.com/de/vertrag', $this->urls((new SiteSearch($this->engine))->search('Wartungsvetrag', 'de', [], 1, 10, 'example.com')));
    }

    public function testPhrasesAndExclusion(): void
    {
        $search = new SiteSearch($this->engine);

        $this->assertSame(['https://example.com/de/heizung'], $this->urls($search->search('"Heizung warten"', 'de', [], 1, 10, 'example.com')));
        $this->assertNotContains('https://example.com/de/heizung', $this->urls($search->search('Wartungsvertrag -Heizung', 'de', [], 1, 10, 'example.com')));
        $this->assertContains('https://example.com/de/vertrag', $this->urls($search->search('Wartungsvertrag -Heizung', 'de', [], 1, 10, 'example.com')));
    }

    public function testPagination(): void
    {
        $search = new SiteSearch($this->engine);
        $page1 = $search->search('Wartungsvertrag', 'de', [], 1, 2, 'example.com');
        $page2 = $search->search('Wartungsvertrag', 'de', [], 2, 2, 'example.com');

        $this->assertSame(3, $page1['totalHits']);
        $this->assertSame(2, $page1['totalPages']);
        $this->assertCount(2, $page1['hits']);
        $this->assertCount(1, $page2['hits']);
        $this->assertSame([], array_intersect($this->urls($page1), $this->urls($page2)));
    }

    public function testInfo(): void
    {
        $info = $this->engine->getInfo();

        $this->assertSame(6, $info['documents']);
        $this->assertNotSame('', $info['engine']);
        $this->assertNotSame('', $info['location']);
    }

    /**
     * @param list<int>            $groups
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    protected function document(string $url, string $title, string $description, string $content, string $language = 'de', bool $protected = false, array $groups = [], string $source = 'page', string $record = '', array $extra = []): array
    {
        return [
            'url' => $url,
            'source' => $source,
            'domain' => (string) parse_url($url, PHP_URL_HOST),
            'title' => $title,
            'description' => $description,
            'content' => $content,
            'image' => '',
            'language' => $language,
            'record' => $record,
            ...IndexSchema::normalizeAccessFields($protected, $groups),
            ...$extra,
        ];
    }

    /**
     * @param array{hits: list<array<string, mixed>>} $result
     *
     * @return list<string>
     */
    protected function urls(array $result): array
    {
        return array_map(static fn (array $hit): string => $hit['url'], $result['hits']);
    }
}
