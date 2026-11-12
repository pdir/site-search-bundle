<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Tests;

use Pdir\SiteSearchBundle\Cron\PurgeSearchLogCron;
use Pdir\SiteSearchBundle\DependencyInjection\PdirSiteSearchExtension;
use Pdir\SiteSearchBundle\Search\SiteSearchIndexer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class PdirSiteSearchBundleTest extends TestCase
{
    public function testLoadsTheDefaultConfiguration(): void
    {
        $container = $this->loadExtension([]);

        $this->assertSame('%kernel.project_dir%/var/indexes/contao-site', $container->getParameter('pdir_site_search.index_path'));
        $this->assertSame('%contao.search.index_protected%', $container->getParameter('pdir_site_search.index_protected'));
        $this->assertTrue($container->getParameter('pdir_site_search.filter_by_domain'));
    }

    public function testAppliesCustomConfiguration(): void
    {
        $container = $this->loadExtension([['index_path' => '/tmp/site-search', 'index_protected' => true, 'filter_by_domain' => false]]);

        $this->assertSame('/tmp/site-search', $container->getParameter('pdir_site_search.index_path'));
        $this->assertTrue($container->getParameter('pdir_site_search.index_protected'));
        $this->assertFalse($container->getParameter('pdir_site_search.filter_by_domain'));
    }

    public function testConfiguresTheSearchLog(): void
    {
        $defaults = $this->loadExtension([]);

        $this->assertTrue($defaults->getParameter('pdir_site_search.log.enabled'));
        $this->assertSame(90, $defaults->getParameter('pdir_site_search.log.retention_days'));
        $this->assertSame(3, $defaults->getParameter('pdir_site_search.log.min_searches'));

        $custom = $this->loadExtension([['log' => ['enabled' => false, 'retention_days' => 30, 'min_searches' => 5]]]);

        $this->assertFalse($custom->getParameter('pdir_site_search.log.enabled'));
        $this->assertSame(30, $custom->getParameter('pdir_site_search.log.retention_days'));
        $this->assertSame(5, $custom->getParameter('pdir_site_search.log.min_searches'));
        $this->assertTrue($custom->hasDefinition(PurgeSearchLogCron::class));
    }

    public function testRegistersTheIndexer(): void
    {
        $container = $this->loadExtension([]);

        $this->assertTrue($container->hasDefinition(SiteSearchIndexer::class));
        $this->assertTrue($container->getDefinition(SiteSearchIndexer::class)->hasTag('contao.search_indexer'));
    }

    /**
     * @param list<array<string, mixed>> $configs
     */
    private function loadExtension(array $configs): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new PdirSiteSearchExtension())->load($configs, $container);

        return $container;
    }
}
