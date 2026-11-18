<?php

declare(strict_types=1);

$ll = 'LLL:EXT:api_export/Resources/Private/Language/locallang_db.xlf:';

return [
    'ctrl' => [
        'title' => $ll . 'tx_apiexport_domain_model_detailmap',
        'label' => 'storage_pid',
        'label_alt' => 'detail_pid',
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
        'iconfile' => 'EXT:core/Resources/Public/Icons/T3Icons/svgs/apps/apps-pagetree-page-shortcut.svg',
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
        'feed' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'storage_pid' => [
            'label' => $ll . 'detailmap.storage_pid',
            'description' => $ll . 'detailmap.storage_pid.description',
            'config' => [
                'type' => 'group',
                'allowed' => 'pages',
                'size' => 1,
                'maxitems' => 1,
                'minitems' => 1,
                'fieldControl' => [
                    'editPopup' => ['disabled' => true],
                    'addRecord' => ['disabled' => true],
                    'listModule' => ['disabled' => true],
                ],
            ],
        ],
        'detail_pid' => [
            'label' => $ll . 'detailmap.detail_pid',
            'description' => $ll . 'detailmap.detail_pid.description',
            'config' => [
                'type' => 'group',
                'allowed' => 'pages',
                'size' => 1,
                'maxitems' => 1,
                'minitems' => 1,
                'fieldControl' => [
                    'editPopup' => ['disabled' => true],
                    'addRecord' => ['disabled' => true],
                    'listModule' => ['disabled' => true],
                ],
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'storage_pid, detail_pid, hidden',
        ],
    ],
];
