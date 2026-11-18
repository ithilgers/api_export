<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Security;

/**
 * Token of an API client.
 *
 * A token consists of 32 random bytes, written as 64 lowercase hexadecimal
 * characters. Only its SHA-256 hash and its last 4 characters (hint) are
 * stored. Since every token carries 256 random bits, a fast hash without salt
 * is sufficient.
 */
final class ClientToken
{
    public const LENGTH = 64;
    public const HINT_LENGTH = 4;

    public static function generate(): string
    {
        return bin2hex(random_bytes(self::LENGTH / 2));
    }

    /**
     * Whether the value has the format of a generated token. Case matters.
     */
    public static function isWellFormed(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) === 1;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function hint(string $token): string
    {
        return substr($token, -self::HINT_LENGTH);
    }

    /**
     * Hash of a token nobody knows, for records that must not be usable.
     */
    public static function unknownHash(): string
    {
        return hash('sha256', random_bytes(self::LENGTH / 2));
    }
}
