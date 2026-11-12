<?php

declare(strict_types=1);

use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withSets([__DIR__.'/tools/ecs/vendor/contao/easy-coding-standard/config/contao.php'])
;
