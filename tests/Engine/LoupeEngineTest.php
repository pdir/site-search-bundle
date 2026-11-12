<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Tests\Engine;

use Pdir\SiteSearchBundle\Engine\LoupeEngine;
use Pdir\SiteSearchBundle\Engine\SearchEngineInterface;
use Pdir\SiteSearchBundle\Search\LoupeIndexFactory;

class LoupeEngineTest extends EngineContractTestCase
{
    private string $indexPath;

    protected function tearDown(): void
    {
        $this->engine->drop();
        (new LoupeIndexFactory($this->indexPath))->drop();
        @rmdir(\dirname($this->indexPath));
    }

    protected function createEngine(): SearchEngineInterface
    {
        $this->indexPath = sys_get_temp_dir().'/pdir-site-search-engine-test-'.bin2hex(random_bytes(4)).'/index';

        return new LoupeEngine(new LoupeIndexFactory($this->indexPath));
    }
}
