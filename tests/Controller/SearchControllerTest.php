<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Tests\Controller;

use Doctrine\DBAL\Connection;
use Pdir\SiteSearchBundle\Controller\SearchController;
use Pdir\SiteSearchBundle\Engine\LoupeEngine;
use Pdir\SiteSearchBundle\Log\SearchLog;
use Pdir\SiteSearchBundle\Search\LoupeIndexFactory;
use Pdir\SiteSearchBundle\Search\SiteSearch;
use Pdir\SiteSearchBundle\Tests\Log\SearchLogTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

class SearchControllerTest extends TestCase
{
    private const BROWSER = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/131.0 Safari/537.36';

    private string $indexPath;

    private Connection $connection;

    private SearchController $controller;

    protected function setUp(): void
    {
        $this->indexPath = sys_get_temp_dir().'/pdir-site-search-controller-test-'.bin2hex(random_bytes(4)).'/index';

        $factory = new LoupeIndexFactory($this->indexPath);

        $factory->addDocuments([
            $this->document('https://example.com/de/heizung', 'Heizung warten'),
            $this->document('https://example.com/de/intern', 'Interne Photovoltaik', true),
        ]);

        $this->connection = SearchLogTest::createConnection();
        $this->controller = new SearchController(new SiteSearch(new LoupeEngine($factory)), new SearchLog($this->connection, true, 90, 1));

        $container = new Container();
        $container->set('security.token_storage', new TokenStorage());

        $this->controller->setContainer($container);
    }

    protected function tearDown(): void
    {
        (new LoupeIndexFactory($this->indexPath))->drop();
        @rmdir(\dirname($this->indexPath));
    }

    public function testLogEndpointCountsSearchAndClickWithServerSideHits(): void
    {
        $response = $this->controller->log($this->post(['keywords' => 'Heizung', 'lang' => 'de', 'click' => '1']));

        $this->assertSame(204, $response->getStatusCode());

        $this->assertSame(
            ['term' => 'heizung', 'hits' => 1, 'clicks' => 1],
            array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, $this->connection->fetchAssociative('SELECT term, hits, clicks FROM tl_site_search_log')),
        );
    }

    public function testProtectedHitsDoNotCount(): void
    {
        $this->controller->log($this->post(['keywords' => 'Photovoltaik', 'lang' => 'de']));

        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT hits FROM tl_site_search_log'));
    }

    public function testBotsAreNotLogged(): void
    {
        $this->controller->log($this->post(['keywords' => 'heizung'], 'Googlebot/2.1'));

        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_site_search_log'));
    }

    public function testResultsContainSuggestionWithoutHits(): void
    {
        $this->controller->log($this->post(['keywords' => 'heizung', 'lang' => 'de']));

        $data = json_decode((string) $this->controller->getResults(Request::create('https://example.com/search/results', 'GET', ['keywords' => 'heizugn', 'lang' => 'de']))->getContent(), true);

        // Loupe's typo tolerance finds "Heizung" anyway
        if ($data['totalHits'] > 0) {
            $this->assertNull($data['suggestion']);
        } else {
            $this->assertSame('heizung', $data['suggestion']);
        }

        $data = json_decode((string) $this->controller->getResults(Request::create('https://example.com/search/results', 'GET', ['keywords' => 'hxizxnx', 'lang' => 'de']))->getContent(), true);

        $this->assertSame(0, $data['totalHits']);
        $this->assertNull($data['suggestion'], 'Distance 3 is too far for a 7 character term');

        $data = json_decode((string) $this->controller->getResults(Request::create('https://example.com/search/results', 'GET', ['keywords' => 'heizunx', 'lang' => 'de']))->getContent(), true);

        if (0 === $data['totalHits']) {
            $this->assertSame('heizung', $data['suggestion']);
        }
    }

    public function testPopularEndpoint(): void
    {
        $this->controller->log($this->post(['keywords' => 'heizung', 'lang' => 'de']));
        $this->controller->log($this->post(['keywords' => 'photovoltaik', 'lang' => 'de']));

        $response = $this->controller->getPopular(Request::create('https://example.com/search/popular', 'GET', ['lang' => 'de']));

        $this->assertSame(['terms' => ['heizung']], json_decode((string) $response->getContent(), true));
        $this->assertTrue($response->headers->hasCacheControlDirective('public'));
    }

    /**
     * @param array<string, string> $data
     */
    private function post(array $data, string $userAgent = self::BROWSER): Request
    {
        return Request::create('https://example.com/search/log', 'POST', $data, [], [], ['HTTP_USER_AGENT' => $userAgent]);
    }

    /**
     * @return array<string, mixed>
     */
    private function document(string $url, string $title, bool $protected = false): array
    {
        return [
            'url' => $url,
            'source' => 'page',
            'domain' => 'example.com',
            'title' => $title,
            'description' => '',
            'content' => '',
            'image' => '',
            'language' => 'de',
            'record' => '',
            ...LoupeIndexFactory::normalizeAccessFields($protected, $protected ? [2] : []),
        ];
    }
}
