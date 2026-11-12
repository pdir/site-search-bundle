<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Controller;

use Contao\FrontendUser;
use Pdir\SiteSearchBundle\Log\SearchLog;
use Pdir\SiteSearchBundle\Search\MemberGroups;
use Pdir\SiteSearchBundle\Search\SiteSearch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    path: '/search',
    defaults: [
        '_scope' => 'frontend',
        '_token_check' => false,
    ],
)]
class SearchController extends AbstractController
{
    public function __construct(
        private readonly SiteSearch $siteSearch,
        private readonly SearchLog $searchLog,
        private readonly bool $filterByDomain = true,
    ) {
    }

    /**
     * Query parameters:
     *  - keywords: search query (required, max. 200 characters)
     *  - lang: language of the current page, e.g. "de" (optional)
     *  - page: page number, starting with 1 (optional)
     *  - limit: hits per page, 1–50 (optional, default 10)
     *  - source: only these sources, comma-separated, e.g. "page,news" (optional)
     *
     * Without hits, the answer contains "suggestion" ("Did you mean …?") if the
     * search log knows a similar term.
     */
    #[Route(
        path: '/results',
        name: 'search_results',
        defaults: [
            '_scope' => 'frontend',
            '_token_check' => false,
        ],
        methods: ['GET'],
    )]
    public function getResults(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $language = $request->query->has('lang') ? (string) $request->query->get('lang') : null;

        $result = $this->siteSearch->search(
            (string) $request->query->get('keywords', ''),
            $language,
            MemberGroups::of($user),
            $request->query->getInt('page', 1),
            $request->query->getInt('limit', 10),
            $this->getDomain($request),
            array_filter(explode(',', (string) $request->query->get('source', ''))),
        );

        $result['suggestion'] = null;

        if (0 === $result['totalHits'] && '' !== $result['query'] && $this->searchLog->isEnabled()) {
            $result['suggestion'] = $this->searchLog->getSuggestion($result['query'], $language, $this->getDomain($request));
        }

        $response = new JsonResponse($result);

        if ($user instanceof FrontendUser) {
            // Results depend on the member's groups
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');
        } else {
            $response->setPublic();
            $response->setMaxAge(60);
            $response->setSharedMaxAge(300);
        }

        return $response;
    }

    /**
     * Popular searches (e.g. for the empty search field).
     *
     * Query parameters: lang (optional), limit 1–10 (optional, default 5)
     */
    #[Route(
        path: '/popular',
        name: 'search_popular',
        defaults: [
            '_scope' => 'frontend',
            '_token_check' => false,
        ],
        methods: ['GET'],
    )]
    public function getPopular(Request $request): JsonResponse
    {
        $terms = $this->searchLog->isEnabled()
            ? $this->searchLog->getPopular($request->query->has('lang') ? (string) $request->query->get('lang') : null, $this->getDomain($request), max(1, min(10, $request->query->getInt('limit', 5))))
            : [];

        $response = new JsonResponse(['terms' => $terms]);
        $response->setPublic();
        $response->setMaxAge(300);
        $response->setSharedMaxAge(900);

        return $response;
    }

    /**
     * Counts a search in the anonymous log. The script sends it when a visitor
     * selects a suggestion (click = 1); searches on the results page are logged by
     * the results module itself.
     *
     * POST parameters: keywords, lang (optional), click (optional)
     */
    #[Route(
        path: '/log',
        name: 'search_log',
        defaults: [
            '_scope' => 'frontend',
            '_token_check' => false,
        ],
        methods: ['POST'],
    )]
    public function log(Request $request): Response
    {
        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->setPrivate();

        $keywords = mb_substr(trim((string) $request->request->get('keywords', '')), 0, SiteSearch::MAX_QUERY_LENGTH);

        if (!$this->searchLog->isEnabled() || '' === $keywords || SearchLog::isBot($request->headers->get('User-Agent'))) {
            return $response;
        }

        $language = $request->request->has('lang') ? (string) $request->request->get('lang') : null;

        // Count the hits ourselves instead of trusting the client, and as a guest: only
        // terms with public hits can become public suggestions
        $hits = $this->siteSearch->search($keywords, $language, [], 1, 1, $this->getDomain($request))['totalHits'];

        $this->searchLog->log($keywords, $language, $request->getHost(), $hits, $request->request->getBoolean('click'));

        return $response;
    }

    private function getDomain(Request $request): string|null
    {
        return $this->filterByDomain ? $request->getHost() : null;
    }
}
