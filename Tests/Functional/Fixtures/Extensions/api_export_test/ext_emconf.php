<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'API Export – test fixture',
    'description' => 'Listener for ModifyFeedItemEvent (functional tests only).',
    'category' => 'misc',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'api_export' => '',
        ],
    ],
];
