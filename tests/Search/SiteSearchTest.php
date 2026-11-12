<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Tests\Search;

use Pdir\SiteSearchBundle\Engine\LoupeEngine;
use Pdir\SiteSearchBundle\Search\LoupeIndexFactory;
use Pdir\SiteSearchBundle\Search\SiteSearch;
use PHPUnit\Framework\TestCase;

class SiteSearchTest extends TestCase
{
    private string $projectDir;

    private SiteSearch $siteSearch;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/pdir-site-search-test-'.bin2hex(random_bytes(4));

        $factory = new LoupeIndexFactory($this->projectDir.'/var/indexes/contao-site');
        $longText = str_repeat('Füllwort ', 80).'Hier steht etwas über den Wartungsvertrag für Heizungen. '.str_repeat('Füllwort ', 80);

        $factory->create()->addDocuments([
            $this->document('https://example.com/de/heizung', 'Heizung warten', 'Wir warten Ihre Heizung.', $longText, 'de'),
            $this->document('https://example.com/de/vertrag', 'Wartungsvertrag', 'Alles zum Wartungsvertrag', 'Inhalt der Seite', 'de'),
            $this->document('https://example.com/de/mitglieder', 'Wartungsvertrag für Mitglieder', '', 'Interner Wartungsvertrag <script>alert(1)</script>', 'de', true, [2]),
            $this->document('https://example.com/en/contract', 'Wartungsvertrag (English)', 'Maintenance contract', '', 'en'),
            $this->document('https://other.example.org/de/vertrag', 'Wartungsvertrag anderer Domain', '', '', 'de'),
        ]);

        $this->siteSearch = new SiteSearch(new LoupeEngine($factory));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testEmptyQueryReturnsNoHits(): void
    {
        $result = $this->siteSearch->search('   ', 'de');

        $this->assertSame([], $result['hits']);
        $this->assertSame(0, $result['totalHits']);
    }

    public function testFiltersByLanguage(): void
    {
        $urls = $this->urls($this->siteSearch->search('Wartungsvertrag', 'de'));

        $this->assertNotContains('https://example.com/en/contract', $urls);
        $this->assertContains('https://example.com/de/vertrag', $urls);
    }

    public function testRegionalLanguageCodeIsNormalized(): void
    {
        $this->assertSame($this->urls($this->siteSearch->search('Wartungsvertrag', 'de')), $this->urls($this->siteSearch->search('Wartungsvertrag', 'de-DE')));
    }

    public function testInvalidLanguageIsIgnored(): void
    {
        $this->assertSame('protected = 0', LoupeEngine::formatFilter($this->siteSearch->buildFilter("de' OR 1=1", [])));
    }

    public function testFiltersByDomain(): void
    {
        $this->assertNotContains('https://other.example.org/de/vertrag', $this->urls($this->siteSearch->search('Wartungsvertrag', 'de', [], 1, 10, 'example.com')));
        $this->assertSame(['https://other.example.org/de/vertrag'], $this->urls($this->siteSearch->search('Wartungsvertrag', 'de', [], 1, 10, 'OTHER.example.org')));
    }

    public function testInvalidDomainIsIgnored(): void
    {
        $this->assertSame('protected = 0', LoupeEngine::formatFilter($this->siteSearch->buildFilter(null, [], "example.com' OR 1=1")));
    }

    public function testContentIsNotReturned(): void
    {
        foreach ($this->siteSearch->search('Wartungsvertrag', 'de', [2])['hits'] as $hit) {
            $this->assertArrayNotHasKey('content', $hit);
        }
    }

    public function testFormattedSnippetIsHtmlSafe(): void
    {
        $hits = $this->siteSearch->search('Wartungsvertrag', 'de', [2], 1, 10, 'example.com')['hits'];
        $hit = array_values(array_filter($hits, static fn (array $hit): bool => 'https://example.com/de/mitglieder' === $hit['url']))[0];

        $this->assertStringNotContainsString('<script>', $hit['_formatted']['description']);
        $this->assertStringContainsString('&lt;script&gt;', $hit['_formatted']['description']);
        $this->assertStringContainsString('<em>Wartungsvertrag</em>', $hit['_formatted']['description']);
    }

    public function testMetaDescriptionIsPreferredIfItMatches(): void
    {
        $hits = $this->siteSearch->search('Wartungsvertrag', 'de', [], 1, 10, 'example.com')['hits'];
        $hit = array_values(array_filter($hits, static fn (array $hit): bool => 'https://example.com/de/vertrag' === $hit['url']))[0];

        $this->assertSame('Alles zum Wartungsvertrag', $hit['description']);
    }

    public function testProtectedPagesAreHiddenFromGuests(): void
    {
        $this->assertNotContains('https://example.com/de/mitglieder', $this->urls($this->siteSearch->search('Wartungsvertrag', 'de')));
    }

    public function testProtectedPagesAreHiddenFromOtherGroups(): void
    {
        $this->assertNotContains('https://example.com/de/mitglieder', $this->urls($this->siteSearch->search('Wartungsvertrag', 'de', [5])));
    }

    public function testProtectedPagesAreShownToGroupMembers(): void
    {
        $this->assertContains('https://example.com/de/mitglieder', $this->urls($this->siteSearch->search('Wartungsvertrag', 'de', [1, 2])));
    }

    public function testSortsByRelevanceNotAlphabetically(): void
    {
        $urls = $this->urls($this->siteSearch->search('Wartungsvertrag', 'de'));

        // "Heizung warten" comes first alphabetically, but only mentions the term in the
        // page text
        $this->assertSame('https://example.com/de/vertrag', $urls[0]);
    }

    public function testPagination(): void
    {
        $page1 = $this->siteSearch->search('Wartungsvertrag', 'de', [], 1, 1, 'example.com');
        $page2 = $this->siteSearch->search('Wartungsvertrag', 'de', [], 2, 1, 'example.com');

        $this->assertSame(2, $page1['totalHits']);
        $this->assertSame(2, $page1['totalPages']);
        $this->assertCount(1, $page1['hits']);
        $this->assertNotSame($this->urls($page1), $this->urls($page2));
    }

    public function testHitsPerPageIsLimited(): void
    {
        $this->assertSame(SiteSearch::MAX_HITS_PER_PAGE, $this->siteSearch->search('Wartungsvertrag', 'de', [], 1, 10000)['hitsPerPage']);
    }

    public function testDescriptionIsShortSnippetAroundMatch(): void
    {
        $hits = $this->siteSearch->search('Wartungsvertrag', 'de')['hits'];
        $hit = array_values(array_filter($hits, static fn (array $hit): bool => 'https://example.com/de/heizung' === $hit['url']))[0];

        $this->assertStringContainsString('Wartungsvertrag', $hit['description']);
        $this->assertLessThan(400, mb_strlen($hit['description']));
        $this->assertStringNotContainsString('<em>', $hit['description']);
        $this->assertStringContainsString('<em>Wartungsvertrag</em>', $hit['_formatted']['description']);
    }

    /**
     * @param list<int> $groups
     *
     * @return array<string, mixed>
     */
    private function document(string $url, string $title, string $description, string $content, string $language, bool $protected = false, array $groups = []): array
    {
        return [
            'url' => $url,
            'domain' => (string) parse_url($url, PHP_URL_HOST),
            'title' => $title,
            'description' => $description,
            'content' => $content,
            'language' => $language,
            'source' => 'page',
            'image' => '',
            ...LoupeIndexFactory::normalizeAccessFields($protected, $groups),
        ];
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

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
