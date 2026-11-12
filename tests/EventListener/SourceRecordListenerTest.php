<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Tests\EventListener;

use Contao\DataContainer;
use Pdir\SiteSearchBundle\Engine\LoupeEngine;
use Pdir\SiteSearchBundle\EventListener\SourceRecordListener;
use Pdir\SiteSearchBundle\Search\LoupeIndexFactory;
use Pdir\SiteSearchBundle\Search\SourceIndexer;
use Pdir\SiteSearchBundle\Source\SourceRegistry;
use Pdir\SiteSearchBundle\Tests\Fixtures\CompanyProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SourceRecordListenerTest extends TestCase
{
    private string $indexPath;

    protected function setUp(): void
    {
        $this->indexPath = sys_get_temp_dir().'/pdir-site-search-listener-test-'.bin2hex(random_bytes(4)).'/index';
    }

    protected function tearDown(): void
    {
        (new LoupeIndexFactory($this->indexPath))->drop();
        @rmdir(\dirname($this->indexPath));
        unset($GLOBALS['TL_DCA']);
    }

    public function testUpdatesTheIndexOnSubmitAndDelete(): void
    {
        $factory = new LoupeIndexFactory($this->indexPath);
        $provider = new CompanyProvider();
        $registry = new SourceRegistry([$provider]);
        $listener = new SourceRecordListener($registry, new SourceIndexer(new LoupeEngine($factory), $registry));

        $listener('tl_news');
        $this->assertArrayNotHasKey('tl_news', $GLOBALS['TL_DCA'] ?? []);

        $listener('tl_company');

        $config = $GLOBALS['TL_DCA']['tl_company']['config'];
        $config['onsubmit_callback'][0]($this->dataContainer(1));

        $this->assertSame('Müller GmbH', $factory->create()->getDocument('https://www.example.com/de/firmen.html#company-1')['title']);

        $config['ondelete_callback'][0]($this->dataContainer(1));
        $this->assertNull($factory->create()->getDocument('https://www.example.com/de/firmen.html#company-1'));
    }

    public function testErrorsAreLoggedAndDoNotBreakSaving(): void
    {
        $provider = new CompanyProvider();
        $provider->companies[1]['url'] = '/relative';

        $registry = new SourceRegistry([$provider]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('error')
        ;

        (new SourceRecordListener($registry, new SourceIndexer(new LoupeEngine(new LoupeIndexFactory($this->indexPath)), $registry), $logger))->onSubmit('tl_company', 1);
    }

    private function dataContainer(int $id): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc
            ->method('__get')
            ->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? $id : null)
        ;

        return $dc;
    }
}
