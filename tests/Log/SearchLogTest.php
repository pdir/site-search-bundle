<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Tests\Log;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Pdir\SiteSearchBundle\Log\SearchLog;
use PHPUnit\Framework\TestCase;

class SearchLogTest extends TestCase
{
    private Connection $connection;

    private SearchLog $log;

    protected function setUp(): void
    {
        $this->connection = self::createConnection();
        $this->log = new SearchLog($this->connection, true, 90, 2);
    }

    public static function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // Same structure as contao/dca/tl_site_search_log.php
        $connection->executeStatement(
            <<<'SQL'
                CREATE TABLE tl_site_search_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tstamp INTEGER NOT NULL DEFAULT 0,
                    day INTEGER NOT NULL DEFAULT 0,
                    domain VARCHAR(191) NOT NULL DEFAULT '',
                    language VARCHAR(2) NOT NULL DEFAULT '',
                    term VARCHAR(100) NOT NULL DEFAULT '',
                    searches INTEGER NOT NULL DEFAULT 0,
                    hits INTEGER NOT NULL DEFAULT 0,
                    clicks INTEGER NOT NULL DEFAULT 0
                )
                SQL,
        );

        $connection->executeStatement('CREATE UNIQUE INDEX day_term ON tl_site_search_log (day, domain, language, term)');

        return $connection;
    }

    public function testNormalizesTerms(): void
    {
        $this->assertSame('heizung wartung', SearchLog::normalize("  Heizung \n  WARTUNG "));
        $this->assertSame('über', SearchLog::normalize('Über'));
        $this->assertNull(SearchLog::normalize('a'));
        $this->assertNull(SearchLog::normalize(str_repeat('x', 101)));
        $this->assertNull(SearchLog::normalize("ab\u{0007}c"));
    }

    public function testDetectsBots(): void
    {
        $this->assertTrue(SearchLog::isBot(null));
        $this->assertTrue(SearchLog::isBot('Mozilla/5.0 (compatible; Googlebot/2.1)'));
        $this->assertTrue(SearchLog::isBot('Contao/5.5 Escargot'));
        $this->assertFalse(SearchLog::isBot('Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Safari/605.1.15'));
    }

    public function testCountsSearchesPerDayDomainLanguageAndTerm(): void
    {
        $this->log->log('Heizung', 'de-DE', 'Example.com', 5);
        $this->log->log('heizung ', 'de', 'example.com', 7, true);
        $this->log->log('heizung', 'en', 'example.com', 1);

        $rows = $this->connection->fetchAllAssociative('SELECT language, domain, term, searches, hits, clicks FROM tl_site_search_log ORDER BY language');

        $this->assertSame(
            [
                ['language' => 'de', 'domain' => 'example.com', 'term' => 'heizung', 'searches' => 2, 'hits' => 7, 'clicks' => 1],
                ['language' => 'en', 'domain' => 'example.com', 'term' => 'heizung', 'searches' => 1, 'hits' => 1, 'clicks' => 0],
            ],
            array_map(static fn (array $row): array => array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, $row), $rows),
        );
    }

    public function testStoresNoPersonalData(): void
    {
        $columns = array_keys($this->connection->createSchemaManager()->listTableColumns('tl_site_search_log'));

        $this->assertSame(['id', 'tstamp', 'day', 'domain', 'language', 'term', 'searches', 'hits', 'clicks'], $columns);
    }

    public function testDoesNothingIfDisabled(): void
    {
        (new SearchLog($this->connection, false))->log('heizung', 'de', 'example.com', 1);

        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_site_search_log'));
    }

    public function testPurgesOldEntries(): void
    {
        $this->insert('alt', 10, 1, strtotime('today') - 91 * 86400);
        $this->insert('neu', 10, 1, strtotime('today') - 89 * 86400);

        $this->assertSame(1, $this->log->purge());
        $this->assertSame(['neu'], $this->connection->fetchFirstColumn('SELECT term FROM tl_site_search_log'));
    }

    public function testPopularNeedsMinimumSearchesAndHits(): void
    {
        $this->insert('heizung', 10, 4);
        $this->insert('wartung', 3, 2);
        $this->insert('einmal', 1, 2);
        $this->insert('ohne treffer', 20, 0);
        $this->insert('heating', 50, 3, null, 'en');
        $this->insert('andere domain', 50, 3, null, 'de', 'other.example.org');

        $this->assertSame(['heizung', 'wartung'], $this->log->getPopular('de', 'example.com'));
        $this->assertSame(['heizung'], $this->log->getPopular('de', 'example.com', 1));
    }

    public function testPopularSumsUpDays(): void
    {
        $this->insert('wartung', 1, 2, strtotime('today'));
        $this->insert('wartung', 1, 2, strtotime('yesterday'));

        $this->assertSame(['wartung'], $this->log->getPopular('de', 'example.com'));
    }

    public function testRelatedTermsShareAWord(): void
    {
        $this->insert('heizung', 10, 4);
        $this->insert('heizung wartung', 5, 2);
        $this->insert('heizungsbau', 3, 1);
        $this->insert('wartung', 8, 1);
        $this->insert('heizung_%', 8, 0);

        $this->assertSame(['heizung wartung', 'heizungsbau'], $this->log->getRelated('Heizung', 'de', 'example.com'));
        $this->assertSame(['heizung', 'wartung', 'heizungsbau'], $this->log->getRelated('heizung wartung', 'de', 'example.com'));
    }

    public function testLikeWildcardsAreEscaped(): void
    {
        $this->insert('heizung', 10, 4);

        $this->assertSame([], $this->log->getRelated('%%%', 'de', 'example.com'));
        $this->assertSame([], $this->log->getRelated('h_izung', 'de', 'example.com'));
    }

    public function testSuggestsSimilarFrequentTerm(): void
    {
        $this->insert('wartungsvertrag', 10, 4);
        $this->insert('heizung', 10, 4);
        $this->insert('heizöl', 3, 2);
        $this->insert('heizugn', 2, 0);

        $this->assertSame('wartungsvertrag', $this->log->getSuggestion('wartunsgvertrag', 'de', 'example.com'));
        $this->assertSame('heizung', $this->log->getSuggestion('Heizugn', 'de', 'example.com'));
        $this->assertSame('heizöl', $this->log->getSuggestion('heizol', 'de', 'example.com'));
        $this->assertNull($this->log->getSuggestion('heizung', 'de', 'example.com'));
        $this->assertNull($this->log->getSuggestion('xyz', 'de', 'example.com'));
        $this->assertNull($this->log->getSuggestion('photovoltaik', 'de', 'example.com'));
    }

    public function testMoreFrequentTermWinsOnTie(): void
    {
        $this->insert('haus', 3, 1);
        $this->insert('maus', 9, 1);

        $this->assertSame('maus', $this->log->getSuggestion('raus', 'de', 'example.com'));
    }

    public function testLevenshteinCountsCharacters(): void
    {
        $this->assertSame(1, SearchLog::levenshtein('heizöl', 'heizol'));
        $this->assertSame(0, SearchLog::levenshtein('über', 'über'));
        $this->assertSame(2, SearchLog::levenshtein('äöü', 'aöu'));
    }

    public function testStatistics(): void
    {
        $this->insert('heizung', 10, 4, null, 'de', 'example.com', 3);
        $this->insert('heizung', 2, 4, null, 'en', 'example.com');
        $this->insert('photovoltaik', 5, 0);
        $this->insert('alt', 99, 0, strtotime('today') - 40 * 86400);

        $stats = $this->log->getStatistics(30);

        $this->assertSame(17, $stats['searches']);
        $this->assertSame(3, $stats['clicks']);
        $this->assertSame(5, $stats['zeroHits']);
        $this->assertSame(2, $stats['terms']);
        $this->assertSame([['term' => 'heizung', 'searches' => 12, 'hits' => 4, 'clicks' => 3], ['term' => 'photovoltaik', 'searches' => 5, 'hits' => 0, 'clicks' => 0]], $stats['top']);
        $this->assertSame([['term' => 'photovoltaik', 'searches' => 5]], $stats['noHits']);
        $this->assertSame(116, $this->log->getStatistics(90)['searches']);
    }

    private function insert(string $term, int $searches, int $hits, int|null $day = null, string $language = 'de', string $domain = 'example.com', int $clicks = 0): void
    {
        $this->connection->insert('tl_site_search_log', [
            'tstamp' => time(),
            'day' => $day ?? strtotime('today'),
            'domain' => $domain,
            'language' => $language,
            'term' => $term,
            'searches' => $searches,
            'hits' => $hits,
            'clicks' => $clicks,
        ]);
    }
}
