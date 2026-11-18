<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'API Export',
    'description' => 'Token-secured JSON feed API for TYPO3 records: events from sf_event_mgt and news from georgringer/news, extendable via providers. Access is managed per API client with grants per resource.',
    'category' => 'fe',
    'author' => 'Theodor Hilgers',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'sf_event_mgt' => '7.0.0-8.99.99',
            'news' => '12.0.0-13.99.99',
        ],
    ],
];
