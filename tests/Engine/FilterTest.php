<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Tests\Engine;

use Pdir\SiteSearchBundle\Engine\Filter;
use Pdir\SiteSearchBundle\Engine\LoupeEngine;
use Pdir\SiteSearchBundle\Engine\MeilisearchEngine;
use Pdir\SiteSearchBundle\Engine\TypesenseEngine;
use PHPUnit\Framework\TestCase;

class FilterTest extends TestCase
{
    public function testFormatsForAllEngines(): void
    {
        $filter = Filter::create('Example.com', 'DE', ['page', 'news'], [1, 2], 'news:5');

        $loupe = "domain = 'example.com' AND language = 'de' AND (source = 'page' OR source = 'news') AND record = 'news:5' AND (protected = 0 OR groups = 1 OR groups = 2)";
        $this->assertSame($loupe, LoupeEngine::formatFilter($filter));
        $this->assertSame($loupe, MeilisearchEngine::formatFilter($filter));
        $this->assertSame('domain:=`example.com` && language:=`de` && (source:=`page` || source:=`news`) && record:=`news:5` && (protected:=0 || groups:=1 || groups:=2)', TypesenseEngine::formatFilter($filter));
    }

    public function testGuestsOnlySeeUnprotectedDocuments(): void
    {
        $this->assertSame('protected = 0', LoupeEngine::formatFilter(Filter::create(memberGroups: [])));
        $this->assertSame('protected:=0', TypesenseEngine::formatFilter(Filter::create(memberGroups: [0, -1, 'x'])));
    }

    public function testNoConditions(): void
    {
        $this->assertSame('', LoupeEngine::formatFilter(Filter::all()));
        $this->assertSame('', TypesenseEngine::formatFilter(Filter::create()));
    }

    public function testDropsInjectionAttempts(): void
    {
        $filter = Filter::create(
            "example.com' OR '1'='1",
            "de' OR 1=1",
            ["page' OR source = 'x", 'news` || id:*', 'news'],
            ['1 OR 1=1', 3],
            'news:1` || protected:=1',
        );

        $this->assertNull($filter->domain);
        $this->assertNull($filter->language);
        $this->assertNull($filter->record);
        $this->assertSame(['news'], $filter->sources);
        $this->assertSame([1, 3], $filter->memberGroups, 'intval: "1 OR 1=1" becomes 1');
        $this->assertSame("source = 'news' AND (protected = 0 OR groups = 1 OR groups = 3)", LoupeEngine::formatFilter($filter));
        $this->assertStringNotContainsString('`', str_replace(['`news`'], '', TypesenseEngine::formatFilter($filter)));
    }

    public function testShortcuts(): void
    {
        $this->assertSame("source = 'news'", LoupeEngine::formatFilter(Filter::source('news')));
        $this->assertSame("record = 'company:12'", LoupeEngine::formatFilter(Filter::record('company:12')));
        $this->assertNull(Filter::source('bad name')->sources[0] ?? null);
    }
}
