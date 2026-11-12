<?php

declare(strict_types=1);

/*
 * Anonymous search log (see Pdir\SiteSearchBundle\Log\SearchLog): one row per
 * day, domain, language and term. No personal data.
 */
$GLOBALS['TL_DCA']['tl_site_search_log'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'day,domain,language,term' => 'unique',
                'term' => 'index',
            ],
        ],
    ],
    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        // Midnight (Unix timestamp) of the day
        'day' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'domain' => ['sql' => ['type' => 'string', 'length' => 191, 'default' => '']],
        'language' => ['sql' => ['type' => 'string', 'length' => 2, 'default' => '']],
        'term' => ['sql' => ['type' => 'string', 'length' => 100, 'default' => '']],
        'searches' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        // Hit count of the latest search
        'hits' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'clicks' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
    ],
];
