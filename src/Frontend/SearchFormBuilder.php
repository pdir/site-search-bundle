<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Frontend;

use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\PageModel;
use Pdir\SiteSearchBundle\Log\SearchLog;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the template data of the search form (used by the search module, the
 * content element and the results module).
 *
 * The form works without JavaScript (GET to the results page); the script
 * public/js/site-search.js adds the suggestions as a WAI-ARIA combobox.
 */
class SearchFormBuilder
{
    public const SUGGESTION_LIMIT = 8;

    public function __construct(
        private readonly UrlGeneratorInterface $router,
        private readonly ContentUrlGenerator $contentUrlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly SearchLog|null $searchLog = null,
    ) {
    }

    /**
     * @param int|string|null $jumpTo   ID of the results page (null = current page)
     * @param string|null     $endpoint Custom search endpoint (null = route "search_results")
     * @param bool            $popular  Show popular searches when the field is focused and empty
     *
     * @return array<string, mixed>
     */
    public function build(Request $request, string $id, int|string|null $jumpTo = null, string|null $endpoint = null, bool $autofocus = false, bool $highlight = true, bool $tracking = false, bool $popular = false): array
    {
        $action = $this->getResultsUrl($jumpTo);
        $hasResultsPage = null !== $action;
        $action ??= $request->getBaseUrl().$request->getPathInfo();

        $log = null !== $this->searchLog && $this->searchLog->isEnabled();
        $t = fn (string $key, array $params = []): string => $this->translator->trans('MSC.siteSearch.'.$key, $params, 'contao_default');

        return [
            'id' => $id,
            'action' => $action,
            'keywords' => mb_substr(trim((string) $request->query->get('keywords', '')), 0, 200),
            'autofocus' => $autofocus,
            'config' => [
                'endpoint' => $endpoint ?: $this->router->generate('search_results'),
                'lang' => $request->getLocale(),
                'limit' => self::SUGGESTION_LIMIT,
                'minLength' => 2,
                'highlight' => $highlight,
                'tracking' => $tracking,
                // Only link "show all results" if there is a results page
                'resultsUrl' => $hasResultsPage ? $action : null,
                // Anonymous search log: selected suggestions are counted
                'logEndpoint' => $log ? $this->router->generate('search_log') : null,
                'popularEndpoint' => $log && $popular ? $this->router->generate('search_popular') : null,
                'messages' => [
                    'results' => $t('resultsCount', ['%s']),
                    'noResults' => $t('noResults'),
                    'showAll' => $t('showAll', ['%s']),
                    'error' => $t('error'),
                    'didYouMean' => $t('didYouMean', ['%s']),
                    'popular' => $t('popular'),
                ],
            ],
        ];
    }

    private function getResultsUrl(int|string|null $jumpTo): string|null
    {
        if (!$jumpTo || !($page = PageModel::findById((int) $jumpTo))) {
            return null;
        }

        try {
            return $this->contentUrlGenerator->generate($page);
        } catch (\Throwable) {
            return null;
        }
    }
}
