<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Tests\ContaoManager;

use Pdir\SiteSearchBundle\ContaoManager\Plugin;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Loader\AttributeFileLoader;
use Symfony\Component\Routing\Loader\YamlFileLoader;

class PluginTest extends TestCase
{
    public function testLoadsTheSearchRoute(): void
    {
        $locator = new FileLocator();

        $resolver = new LoaderResolver([
            new YamlFileLoader($locator),
            new AttributeFileLoader($locator, new AttributeRouteControllerLoader()),
        ]);

        $routes = (new Plugin())->getRouteCollection($resolver, $this->createMock(KernelInterface::class));

        $this->assertNotNull($routes);
        $this->assertSame(['search_results', 'search_popular', 'search_log', 'pdir_site_search_backend'], array_keys($routes->all()));
        $this->assertSame(['POST'], $routes->get('search_log')->getMethods());
        $this->assertSame('/contao/site-search', $routes->get('pdir_site_search_backend')->getPath());
        $this->assertSame('backend', $routes->get('pdir_site_search_backend')->getDefault('_scope'));

        $route = $routes->get('search_results');

        $this->assertNotNull($route);
        $this->assertSame('/search/results', $route->getPath());
        $this->assertSame(['GET'], $route->getMethods());
        $this->assertSame('frontend', $route->getDefault('_scope'));
    }
}
