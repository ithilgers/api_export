<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Http;

use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Uniform JSON error response of the feed ({"error": …, "message": …}).
 *
 * Carries the same protective headers as the regular feed response, so that
 * errors, too, are neither cached by proxies/CDNs nor interpreted by the
 * browser as a different content type.
 */
final class FeedErrorResponse extends JsonResponse
{
    public function __construct(int $status, string $error, string $message, array $headers = [])
    {
        parent::__construct(
            ['error' => $error, 'message' => $message],
            $status,
            array_merge(ProtectionHeaders::HEADERS, $headers),
            // Plain JSON, not embedded in HTML -> no "-escapes.
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
