<?php

declare(strict_types=1);

use Ithilgers\ApiExport\Form\ItemsProcessor\GrantResourceItems;

/*
 * Grant: which data an API client receives for one resource
 * (inline child of an API client, at most one per resource).
 *
 * A grant corresponds to an instance of the resource's list plugin:
 * storage folders, categories, maximum number of items, detail pages and,
 * via a FlexForm per resource, display settings such as time range or sorting.
 *
 * Further resources add an item to "resource" and a FlexForm to "settings"
 * (ds) via a TCA override.
 */

$ll = 'LLL:EXT:api_export/Resources/Private/Language/locallang_db.xlf:';

return [
    'ctrl' => [
        'title' => $ll . 'tx_apiexport_domain_model_feed',
        'label' => 'resource',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'sortby' => 'sorting',
        'hideTable' => true,
        'adminOnly' => true,
        'security' => [
            // The inline child is created on the pid of its client (possibly a
            // regular page), so allow it regardless of the page type.
            'ignorePageTypeRestriction' => true,
        ],
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
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
        'client' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'resource' => [
            'label' => $ll . 'feed.resource',
            'description' => $ll . 'feed.resource.description',
            'onChange' => 'reload',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'required' => true,
                'items' => [
                    ['label' => 'Events (sf_event_mgt)', 'value' => 'events'],
                    ['label' => 'News (georgringer/news)', 'value' => 'news'],
                ],
                // One grant per resource: resources the client already has
                // a grant for are not offered again.
                'itemsProcFunc' => GrantResourceItems::class . '->removeUsedResources',
            ],
        ],
        'storage_pages' => [
            'label' => $ll . 'feed.storage_pages',
            'description' => $ll . 'feed.storage_pages.description',
            'config' => [
                'type' => 'group',
                'allowed' => 'pages',
                'size' => 5,
                'maxitems' => 50,
                'fieldControl' => [
                    'editPopup' => ['disabled' => true],
                    'addRecord' => ['disabled' => true],
                    'listModule' => ['disabled' => true],
                ],
            ],
        ],
        'recursive' => [
            'label' => $ll . 'feed.recursive',
            'description' => $ll . 'feed.recursive.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'default' => 0,
                'items' => [
                    ['label' => $ll . 'feed.recursive.0', 'value' => 0],
                    ['label' => $ll . 'feed.recursive.1', 'value' => 1],
                    ['label' => $ll . 'feed.recursive.2', 'value' => 2],
                    ['label' => $ll . 'feed.recursive.3', 'value' => 3],
                    ['label' => $ll . 'feed.recursive.4', 'value' => 4],
                    ['label' => $ll . 'feed.recursive.250', 'value' => 250],
                ],
            ],
        ],
        'categories' => [
            'label' => $ll . 'feed.categories',
            'description' => $ll . 'feed.categories.description',
            'config' => [
                'type' => 'category',
                'relationship' => 'oneToMany',
            ],
        ],
        'category_conjunction' => [
            'label' => $ll . 'feed.category_conjunction',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'default' => 'or',
                'items' => [
                    ['label' => $ll . 'feed.category_conjunction.or', 'value' => 'or'],
                    ['label' => $ll . 'feed.category_conjunction.and', 'value' => 'and'],
                    ['label' => $ll . 'feed.category_conjunction.notor', 'value' => 'notor'],
                    ['label' => $ll . 'feed.category_conjunction.notand', 'value' => 'notand'],
                ],
            ],
        ],
        'category_include_subcategories' => [
            'label' => $ll . 'feed.category_include_subcategories',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 0,
            ],
        ],
        'max_items' => [
            'label' => $ll . 'feed.max_items',
            'description' => $ll . 'feed.max_items.description',
            'config' => [
                'type' => 'number',
                'size' => 5,
                'default' => 0,
                'range' => [
                    'lower' => 0,
                ],
            ],
        ],
        'settings' => [
            'label' => $ll . 'feed.settings',
            'displayCond' => 'FIELD:resource:REQ:true',
            'config' => [
                'type' => 'flex',
                'ds_pointerField' => 'resource',
                'ds' => [
                    'default' => '<T3DataStructure><ROOT><type>array</type><el></el></ROOT></T3DataStructure>',
                    'events' => 'FILE:EXT:api_export/Configuration/FlexForms/Events.xml',
                    'news' => 'FILE:EXT:api_export/Configuration/FlexForms/News.xml',
                ],
            ],
        ],
        'detail_pid' => [
            'label' => $ll . 'feed.detail_pid',
            'description' => $ll . 'feed.detail_pid.description',
            'config' => [
                'type' => 'group',
                'allowed' => 'pages',
                'size' => 1,
                'maxitems' => 1,
                'fieldControl' => [
                    'editPopup' => ['disabled' => true],
                    'addRecord' => ['disabled' => true],
                    'listModule' => ['disabled' => true],
                ],
            ],
        ],
        'detail_map' => [
            'label' => $ll . 'feed.detail_map',
            'description' => $ll . 'feed.detail_map.description',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_apiexport_domain_model_detailmap',
                'foreign_field' => 'feed',
                'foreign_sortby' => 'sorting',
                'appearance' => [
                    'useSortable' => true,
                    'collapseAll' => false,
                    'expandSingle' => true,
                    'levelLinksPosition' => 'top',
                    'showSynchronizationLink' => false,
                    'showAllLocalizationLink' => false,
                    'showPossibleLocalizationRecords' => false,
                    'newRecordLinkAddTitle' => $ll . 'feed.detail_map.add',
                ],
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;' . $ll . 'feed.tab.data,
                    resource, hidden,
                    storage_pages, recursive,
                    categories, category_conjunction, category_include_subcategories,
                    max_items,
                    settings,
                --div--;' . $ll . 'feed.tab.detailPages,
                    detail_pid, detail_map
            ',
        ],
    ],
];
