<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Headers for every response to a feed URL, successful or not.
 *
 * The content depends on the token and must never end up in a shared cache,
 * be interpreted by the browser as a different content type or be indexed.
 */
final class ProtectionHeaders
{
    public const HEADERS = [
        'Content-Type' => 'application/json; charset=utf-8',
        'Cache-Control' => 'private, no-store',
        'X-Content-Type-Options' => 'nosniff',
        'X-Robots-Tag' => 'noindex, nofollow',
    ];

    public static function apply(ResponseInterface $response): ResponseInterface
    {
        foreach (self::HEADERS as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }
}
