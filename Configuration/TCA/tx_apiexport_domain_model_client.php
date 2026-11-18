<?php

declare(strict_types=1);

$ll = 'LLL:EXT:api_export/Resources/Private/Language/locallang_db.xlf:';

return [
    'ctrl' => [
        'title' => $ll . 'tx_apiexport_domain_model_client',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        // Copies stay inactive until someone deliberately enables them; the
        // ClientTokenHook gives them an unknown token and deactivates them,
        // also when they are copied along with their page.
        'hideAtCopy' => true,
        // Clients, grants and detail page mappings are managed by admins only.
        'adminOnly' => true,
        'default_sortby' => 'title',
        'security' => [
            // Allows creating records on regular pages as well (not only on folders).
            'ignorePageTypeRestriction' => true,
        ],
        'enablecolumns' => [
            'disabled' => 'hidden',
            'starttime' => 'starttime',
            'endtime' => 'endtime',
        ],
        'searchFields' => 'title,description',
        'iconfile' => 'EXT:core/Resources/Public/Icons/T3Icons/svgs/apps/apps-pagetree-folder-contains-shop.svg',
    ],
    'columns' => [
        'hidden' => [
            'label' => $ll . 'field.active',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 0,
                'items' => [
                    ['label' => '', 'invertStateDisplay' => true],
                ],
            ],
        ],
        'starttime' => [
            'label' => $ll . 'client.starttime',
            'config' => [
                'type' => 'datetime',
                'default' => 0,
            ],
        ],
        'endtime' => [
            'label' => $ll . 'client.endtime',
            'config' => [
                'type' => 'datetime',
                'default' => 0,
                'range' => [
                    'upper' => mktime(0, 0, 0, 1, 1, 2038),
                ],
            ],
        ],
        'title' => [
            'label' => $ll . 'client.title',
            'description' => $ll . 'client.title.description',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'max' => 255,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'description' => [
            'label' => $ll . 'client.description',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
            ],
        ],
        // Virtual field without database column: shows a newly generated token
        // once and passes it to the ClientTokenHook, which stores only
        // token_hash and token_hint. Type "none", so that the core never
        // selects it from the database (e.g. as a column in the List module).
        'token' => [
            'label' => $ll . 'client.token',
            'description' => $ll . 'client.token.description',
            'config' => [
                'type' => 'none',
                'renderType' => 'apiExportToken',
            ],
        ],
        'token_hash' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'token_hint' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'feeds' => [
            'label' => $ll . 'client.feeds',
            'description' => $ll . 'client.feeds.description',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_apiexport_domain_model_feed',
                'foreign_field' => 'client',
                'foreign_sortby' => 'sorting',
                'appearance' => [
                    'useSortable' => true,
                    'collapseAll' => true,
                    'expandSingle' => true,
                    'levelLinksPosition' => 'top',
                    'showSynchronizationLink' => false,
                    'showAllLocalizationLink' => false,
                    'showPossibleLocalizationRecords' => false,
                    'newRecordLinkAddTitle' => $ll . 'client.feeds.add',
                ],
            ],
        ],
        'last_used' => [
            'label' => $ll . 'client.last_used',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
                'default' => 0,
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;' . $ll . 'client.tab.general,
                    title, description,
                    token, last_used,
                    hidden, --palette--;' . $ll . 'client.palette.validity;validity,
                --div--;' . $ll . 'client.tab.grants,
                    feeds
            ',
        ],
    ],
    'palettes' => [
        'validity' => [
            'showitem' => 'starttime, endtime',
        ],
    ],
];
