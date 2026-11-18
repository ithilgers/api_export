<?php

declare(strict_types=1);

use Ithilgers\ApiExport\Middleware\FeedTokenAuthenticator;

/*
 * Right after the site resolver (and the maintenance mode), before both user
 * authentications: feed requests are answered without frontend or backend
 * user and never reach redirects, the base redirect or the page resolver.
 */
return [
    'frontend' => [
        'ithilgers/api-export/token-authenticator' => [
            'target' => FeedTokenAuthenticator::class,
            'after' => [
                'typo3/cms-frontend/site',
                'typo3/cms-frontend/maintenance-mode',
            ],
            'before' => [
                'typo3/cms-frontend/backend-user-authentication',
                'typo3/cms-frontend/authentication',
                'typo3/cms-frontend/base-redirect-resolver',
                'typo3/cms-frontend/static-route-resolver',
                'typo3/cms-frontend/page-resolver',
            ],
        ],
    ],
];
