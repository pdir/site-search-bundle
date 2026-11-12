<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('pdir_site_search');
        $treeBuilder->getRootNode()
            ->children()
            ->scalarNode('engine')
            ->info('Search engine as DSN: "loupe://" (default, local, uses index_path), "meilisearch://<key>@<host>:7700?index=<uid>" or "typesense://<key>@<host>:8108?collection=<name>"; "tls=1" for HTTPS. Environment variables: "%env(SITE_SEARCH_ENGINE)%".')
            ->cannotBeEmpty()
            ->defaultValue('loupe://')
            ->end()
            ->scalarNode('index_path')
            ->info('Storage path of the Loupe index (Loupe 0.4 appends ".sqlite").')
            ->cannotBeEmpty()
            ->defaultValue('%kernel.project_dir%/var/indexes/contao-site')
            ->end()
            ->booleanNode('index_protected')
            ->info('Index protected pages. Defaults to the Contao setting contao.search.index_protected. Protected pages are only shown to members of the allowed groups.')
            ->defaultNull()
            ->end()
            ->booleanNode('filter_by_domain')
            ->info('Only return pages of the domain the search is requested from (multi-domain installations).')
            ->defaultTrue()
            ->end()
            ->arrayNode('log')
            ->info('Anonymous search log (terms per day, no personal data): popular and related searches, "Did you mean", statistics in the back end.')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')
            ->defaultTrue()
            ->end()
            ->integerNode('retention_days')
            ->info('Entries older than this are deleted by a daily cron job.')
            ->min(1)
            ->defaultValue(90)
            ->end()
            ->integerNode('min_searches')
            ->info('A term needs this many searches before it is shown to visitors (popular/related searches, "Did you mean").')
            ->min(1)
            ->defaultValue(3)
            ->end()
            ->end()
            ->end()
            ->arrayNode('sources')
            ->info('Built-in document providers (own sources besides the crawled pages).')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('news')
            ->info('Index news entries directly from the database (requires contao/news-bundle). The crawler then skips their reader URLs.')
            ->defaultFalse()
            ->end()
            ->end()
            ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
