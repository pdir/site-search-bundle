<?php

declare(strict_types=1);

$GLOBALS['TL_DCA']['tl_module']['palettes']['site_search'] = '{title_legend},name,headline,type;{config_legend},siteSearchAutoFocus,siteSearchHighlight,siteSearchTracking,siteSearchPopular,siteSearchBaseUrl;{redirect_legend},jumpTo;{template_legend:hide},customTpl;{protected_legend:hide},protected;{expert_legend:hide},cssID';

$GLOBALS['TL_DCA']['tl_module']['palettes']['site_search_results'] = '{title_legend},name,headline,type;{config_legend},perPage,siteSearchHighlight,siteSearchAutoFocus,siteSearchTracking,siteSearchPopular,siteSearchBaseUrl;{template_legend:hide},customTpl;{protected_legend:hide},protected;{expert_legend:hide},cssID';

$GLOBALS['TL_DCA']['tl_module']['fields']['siteSearchAutoFocus'] = [
    'inputType' => 'checkbox',
    'eval' => ['tl_class' => 'w50 m12'],
    'sql' => ['type' => 'boolean', 'default' => false],
];

$GLOBALS['TL_DCA']['tl_module']['fields']['siteSearchHighlight'] = [
    'inputType' => 'checkbox',
    'eval' => ['tl_class' => 'w50 m12'],
    'sql' => ['type' => 'boolean', 'default' => true],
];

$GLOBALS['TL_DCA']['tl_module']['fields']['siteSearchTracking'] = [
    'inputType' => 'checkbox',
    'eval' => ['tl_class' => 'w50 m12'],
    'sql' => ['type' => 'boolean', 'default' => false],
];

// Popular searches (empty field) and related searches (results page) from the search log
$GLOBALS['TL_DCA']['tl_module']['fields']['siteSearchPopular'] = [
    'inputType' => 'checkbox',
    'eval' => ['tl_class' => 'w50 m12'],
    'sql' => ['type' => 'boolean', 'default' => false],
];

// Optional: custom search endpoint; empty = the bundle's own endpoint (route "search_results")
$GLOBALS['TL_DCA']['tl_module']['fields']['siteSearchBaseUrl'] = [
    'inputType' => 'text',
    'eval' => ['maxlength' => 255, 'rgxp' => 'url', 'decodeEntities' => true, 'tl_class' => 'w50 clr'],
    'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
];
