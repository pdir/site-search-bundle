<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Controller\FrontendModule;

use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\ModuleModel;
use Pdir\SiteSearchBundle\Frontend\SearchFormBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Search field with suggestions; submits to the results page (jumpTo).
 */
#[AsFrontendModule(type: 'site_search', category: 'miscellaneous')]
class SiteSearchController extends AbstractFrontendModuleController
{
    public function __construct(private readonly SearchFormBuilder $formBuilder)
    {
    }

    protected function getResponse(FragmentTemplate $template, ModuleModel $model, Request $request): Response
    {
        $template->set(
            'site_search',
            $this->formBuilder->build(
                $request,
                'site-search-mod-'.$model->id,
                $model->jumpTo,
                (string) $model->siteSearchBaseUrl,
                (bool) $model->siteSearchAutoFocus,
                (bool) $model->siteSearchHighlight,
                (bool) $model->siteSearchTracking,
                (bool) $model->siteSearchPopular,
            ),
        );

        return $template->getResponse();
    }
}
