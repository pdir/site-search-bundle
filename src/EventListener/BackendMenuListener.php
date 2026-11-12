<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\EventListener;

use Contao\CoreBundle\Event\MenuEvent;
use Pdir\SiteSearchBundle\Controller\Backend\SiteSearchBackendController;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

/**
 * Links the back end module "site_search" (from BE_MOD, so user group permissions
 * work) to the route of SiteSearchBackendController.
 */
#[AsEventListener(priority: -10)]
class BackendMenuListener
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function __invoke(MenuEvent $event): void
    {
        if ('mainMenu' !== $event->getTree()->getName()) {
            return;
        }

        $node = $event->getTree()->getChild('system')?->getChild('site_search');

        if (null === $node) {
            return;
        }

        $node->setUri($this->router->generate(SiteSearchBackendController::ROUTE));
        $node->setCurrent(SiteSearchBackendController::class === $this->requestStack->getCurrentRequest()?->attributes->get('_controller'));
    }
}
