<?php

declare(strict_types=1);

defined('TYPO3') or die();

use Ithilgers\ApiExport\Form\Element\TokenElement;
use Ithilgers\ApiExport\Hooks\ClientTokenHook;

(static function (): void {
    // Store only hash and hint of a client's token; copies and imports get an
    // unknown token.
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['api_export']
        = ClientTokenHook::class;

    // Form element of the virtual field "token": shows a generated token once.
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1763452800] = [
        'nodeName' => 'apiExportToken',
        'priority' => 40,
        'class' => TokenElement::class,
    ];
})();
