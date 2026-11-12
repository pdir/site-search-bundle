<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Controller\FrontendModule;

use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Routing\ResponseContext\HtmlHeadBag\HtmlHeadBag;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\ModuleModel;
use Pdir\SiteSearchBundle\Frontend\SearchFormBuilder;
use Pdir\SiteSearchBundle\Log\SearchLog;
use Pdir\SiteSearchBundle\Search\MemberGroups;
use Pdir\SiteSearchBundle\Search\SiteSearch;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-rendered results page with pagination. Works without JavaScript and is
 * the target of the search form.
 */
#[AsFrontendModule(type: 'site_search_results', category: 'miscellaneous')]
class SiteSearchResultsController extends AbstractFrontendModuleController
{
    public function __construct(
        private readonly SiteSearch $siteSearch,
        private readonly SearchFormBuilder $formBuilder,
        private readonly ResponseContextAccessor $responseContextAccessor,
        private readonly SearchLog $searchLog,
        private readonly bool $filterByDomain = true,
    ) {
    }

    protected function getResponse(FragmentTemplate $template, ModuleModel $model, Request $request): Response
    {
        $form = $this->formBuilder->build(
            $request,
            'site-search-results-'.$model->id,
            null,
            (string) $model->siteSearchBaseUrl,
            (bool) $model->siteSearchAutoFocus,
            (bool) $model->siteSearchHighlight,
            (bool) $model->siteSearchTracking,
            (bool) $model->siteSearchPopular,
        );

        $template->set('site_search', $form);
        $template->set('highlight', (bool) $model->siteSearchHighlight);
        $template->set('result', null);
        $template->set('pagination', null);
        $template->set('suggestion', null);
        $template->set('related', []);
        $template->set('popular', []);

        $log = $this->searchLog->isEnabled();
        $language = $request->getLocale();
        $domain = $this->filterByDomain ? $request->getHost() : null;

        if ('' === $form['keywords']) {
            if ($log && $model->siteSearchPopular) {
                $template->set('popular', $this->termLinks($form['action'], $this->searchLog->getPopular($language, $domain, 10)));
            }

            return $template->getResponse();
        }

        $memberGroups = MemberGroups::of($this->getUser());

        $result = $this->siteSearch->search(
            $form['keywords'],
            $language,
            $memberGroups,
            $request->query->getInt('page', 1),
            (int) $model->perPage ?: 10,
            $domain,
        );

        $template->set('result', $result);

        if ($log && 1 === $result['page']) {
            // Count each search once (not every page) and without bots; hits as a guest, so
            // terms of protected pages do not become public suggestions
            if (!SearchLog::isBot($request->headers->get('User-Agent'))) {
                $hits = [] === $memberGroups ? $result['totalHits'] : $this->siteSearch->search($form['keywords'], $language, [], 1, 1, $domain)['totalHits'];
                $this->searchLog->log($form['keywords'], $language, $request->getHost(), $hits);
            }

            if (0 === $result['totalHits'] && null !== ($suggestion = $this->searchLog->getSuggestion($form['keywords'], $language, $domain))) {
                $template->set('suggestion', $this->termLinks($form['action'], [$suggestion])[0]);
            }

            if ($model->siteSearchPopular) {
                $template->set('related', $this->termLinks($form['action'], $this->searchLog->getRelated($form['keywords'], $language, $domain)));
            }
        }

        $template->set('pagination', $this->buildPagination($form['action'], $form['keywords'], $result['page'], $result['totalPages']));

        // Search result pages must not end up in search engines
        $this->responseContextAccessor->getResponseContext()?->get(HtmlHeadBag::class)?->setMetaRobots('noindex,follow');

        return $template->getResponse();
    }

    /**
     * @param list<string> $terms
     *
     * @return list<array{term: string, url: string}>
     */
    private function termLinks(string $action, array $terms): array
    {
        return array_map(static fn (string $term): array => ['term' => $term, 'url' => $action.'?'.http_build_query(['keywords' => $term])], $terms);
    }

    /**
     * @return array{previous: string|null, next: string|null, pages: list<array{number: int, url: string, current: bool}>}|null
     */
    private function buildPagination(string $action, string $keywords, int $current, int $total): array|null
    {
        if ($total < 2) {
            return null;
        }

        $url = static fn (int $page): string => $action.'?'.http_build_query(array_filter(['keywords' => $keywords, 'page' => $page > 1 ? $page : null]));
        $pages = [];

        foreach (range(max(1, $current - 2), min($total, $current + 2)) as $number) {
            $pages[] = ['number' => $number, 'url' => $url($number), 'current' => $number === $current];
        }

        return [
            'previous' => $current > 1 ? $url($current - 1) : null,
            'next' => $current < $total ? $url($current + 1) : null,
            'pages' => $pages,
        ];
    }
}
