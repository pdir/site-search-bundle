<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Pdir\SiteSearchBundle\Engine\SearchEngineInterface;
use Pdir\SiteSearchBundle\Log\SearchLog;
use Pdir\SiteSearchBundle\Search\SourceIndexer;
use Pdir\SiteSearchBundle\Source\SourceRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Back end overview: index status per source, reindex of the providers and the
 * statistics of the anonymous search log (popular terms, terms without hits).
 */
#[Route('/contao/site-search', name: self::ROUTE, defaults: ['_scope' => 'backend'], methods: ['GET', 'POST'])]
class SiteSearchBackendController extends AbstractBackendController
{
    public const ROUTE = 'pdir_site_search_backend';

    private const PERIODS = [7, 30, 90];

    public function __construct(
        private readonly SearchEngineInterface $engine,
        private readonly SourceIndexer $sourceIndexer,
        private readonly SourceRegistry $registry,
        private readonly SearchLog $searchLog,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $this->denyAccessUnlessGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'site_search');

        if ($request->isMethod('POST')) {
            return $this->reindex($request);
        }

        $days = \in_array($request->query->getInt('days'), self::PERIODS, true) ? $request->query->getInt('days') : 30;

        try {
            $counts = $this->sourceIndexer->countBySource();
            $info = $this->engine->getInfo();
            $indexError = null;
        } catch (\Throwable $e) {
            $counts = [];
            $info = ['engine' => '', 'location' => '', 'documents' => null, 'size' => null, 'modified' => null];
            $indexError = $e->getMessage();
        }

        return $this->render('@Contao/backend/site_search.html.twig', [
            'title' => $this->translator->trans('MOD.site_search.0', [], 'contao_modules'),
            'headline' => $this->translator->trans('MOD.site_search.0', [], 'contao_modules'),
            'index' => [
                ...$info,
                'counts' => $counts,
                'total' => array_sum($counts),
                'error' => $indexError,
            ],
            'providers' => array_keys($this->registry->all()),
            'reindexed' => $request->query->has('reindexed') ? [
                'source' => (string) $request->query->get('reindexed'),
                'indexed' => $request->query->getInt('indexed'),
                'removed' => $request->query->getInt('removed'),
                'errors' => $request->query->getInt('errors'),
                'failed' => $request->query->getBoolean('failed'),
            ] : null,
            'log' => [
                'enabled' => $this->searchLog->isEnabled(),
                'days' => $days,
                'periods' => self::PERIODS,
                'statistics' => $this->searchLog->isEnabled() ? $this->searchLog->getStatistics($days) : null,
            ],
        ]);
    }

    /**
     * Reindexes one provider source (pages are indexed by the Contao crawler).
     */
    private function reindex(Request $request): Response
    {
        $source = (string) $request->request->get('source');

        if (!$this->registry->has($source)) {
            return new RedirectResponse($this->generateUrl(self::ROUTE));
        }

        @set_time_limit(0);

        try {
            $result = $this->sourceIndexer->reindex($this->registry->get($source));
            $params = ['indexed' => $result['indexed'], 'removed' => $result['removed'], 'errors' => \count($result['errors'])];
        } catch (\Throwable $e) {
            $this->logger?->error(\sprintf('Site search: could not reindex the source "%s": %s', $source, $e->getMessage()), ['exception' => $e]);
            $params = ['failed' => 1];
        }

        return new RedirectResponse($this->generateUrl(self::ROUTE, ['reindexed' => $source, ...$params]));
    }
}
