<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\Controller\ContentElement\AbstractContentElementController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsContentElement;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Pdir\SiteSearchBundle\Frontend\SearchFormBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Search field as content element. tl_content has no settings for it, so the
 * defaults apply and the form submits to the current page. Use the front end
 * module for a separate results page.
 */
#[AsContentElement(type: 'site_search', category: 'texts')]
class SiteSearchController extends AbstractContentElementController
{
    public function __construct(private readonly SearchFormBuilder $formBuilder)
    {
    }

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        $template->set('site_search', $this->formBuilder->build($request, 'site-search-ce-'.$model->id));

        return $template->getResponse();
    }
}
