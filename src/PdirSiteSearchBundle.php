<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class PdirSiteSearchBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
