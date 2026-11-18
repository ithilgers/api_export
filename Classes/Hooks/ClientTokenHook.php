<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Hooks;

use Ithilgers\ApiExport\Security\ClientToken;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\SysLog\Action\Database as SystemLogDatabaseAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Turns the token of an API client into its hash and hint before saving.
 *
 * The token itself only arrives via the virtual field "token" (backend form,
 * CLI command) and never reaches the database, the record history or the log:
 *
 * - "token" with a generated token (64 hex characters): its SHA-256 hash and
 *   its last 4 characters are stored.
 * - "token" in any other format: rejected with an error in the backend; an
 *   existing client keeps its token.
 * - "token_hash" / "token_hint" from outside (copy, import, API) are always
 *   discarded. A new client gets the hash of a random token nobody knows;
 *   if it came with a hash (copy, import), it is deactivated as well.
 * - A new client without token gets the hash of a random token, too.
 *
 * Since token and hash have the same format, they can only be told apart by
 * the field they arrive in. Without this hook, the DataHandler ignores the
 * field "token" (type "none"): no token is stored and a new client has no
 * usable token.
 */
final class ClientTokenHook
{
    private const TABLE = 'tx_apiexport_domain_model_client';
    private const MESSAGE = 'LLL:EXT:api_export/Resources/Private/Language/locallang.xlf:message.tokenInvalid';
    private const FALLBACK_MESSAGE = 'API client: the token was not saved because it is not a generated token (64 hexadecimal characters).';

    public function processDatamap_preProcessFieldArray(
        array &$incomingFieldArray,
        string $table,
        string|int $id,
        DataHandler $dataHandler,
    ): void {
        if ($table !== self::TABLE) {
            return;
        }

        $isNew = !MathUtility::canBeInterpretedAsInteger($id);
        $hasForeignHash = array_key_exists('token_hash', $incomingFieldArray)
            || array_key_exists('token_hint', $incomingFieldArray);
        $token = $incomingFieldArray['token'] ?? null;
        unset($incomingFieldArray['token'], $incomingFieldArray['token_hash'], $incomingFieldArray['token_hint']);

        if (ClientToken::isWellFormed($token)) {
            $incomingFieldArray['token_hash'] = ClientToken::hash($token);
            $incomingFieldArray['token_hint'] = ClientToken::hint($token);
            return;
        }
        if ($token !== null && $token !== '') {
            $dataHandler->log(
                $table,
                $isNew ? 0 : (int)$id,
                $isNew ? SystemLogDatabaseAction::INSERT : SystemLogDatabaseAction::UPDATE,
                0,
                SystemLogErrorClassification::USER_ERROR,
                $this->message()
            );
        }
        if ($isNew) {
            $incomingFieldArray['token_hash'] = ClientToken::unknownHash();
            $incomingFieldArray['token_hint'] = '';
            if ($hasForeignHash) {
                $incomingFieldArray['hidden'] = 1;
            }
        }
    }

    /**
     * Error in the language of the current backend user.
     */
    private function message(): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        $message = $languageService instanceof LanguageService ? $languageService->sL(self::MESSAGE) : '';
        return $message !== '' ? $message : self::FALLBACK_MESSAGE;
    }
}
