<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\DependencyInjection;

use Pdir\SiteSearchBundle\Source\Contao\NewsDocumentProvider;
use Pdir\SiteSearchBundle\Source\DocumentProviderInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class PdirSiteSearchExtension extends Extension
{
    public const PROVIDER_TAG = 'pdir_site_search.document_provider';

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('pdir_site_search.engine', $config['engine']);
        $container->setParameter('pdir_site_search.index_path', $config['index_path']);
        $container->setParameter('pdir_site_search.index_protected', $config['index_protected'] ?? '%contao.search.index_protected%');
        $container->setParameter('pdir_site_search.filter_by_domain', $config['filter_by_domain']);
        $container->setParameter('pdir_site_search.log.enabled', $config['log']['enabled']);
        $container->setParameter('pdir_site_search.log.retention_days', $config['log']['retention_days']);
        $container->setParameter('pdir_site_search.log.min_searches', $config['log']['min_searches']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.yaml');

        $container
            ->registerForAutoconfiguration(DocumentProviderInterface::class)
            ->addTag(self::PROVIDER_TAG)
        ;

        $bundles = $container->hasParameter('kernel.bundles') ? (array) $container->getParameter('kernel.bundles') : [];

        if (!$config['sources']['news'] || !isset($bundles['ContaoNewsBundle'])) {
            $container->removeDefinition(NewsDocumentProvider::class);
        }
    }
}
