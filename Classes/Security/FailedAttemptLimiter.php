<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Security;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\RateLimiter\Storage\CachingFrameworkStorage;

/**
 * Counts feed requests without a valid token per IP address and logs them.
 *
 * Only failed attempts are counted: the rate limiter (sliding window, storage
 * in the cache "ratelimiter") is only created after a failed attempt, so
 * requests with a valid token never touch it and are never blocked – also
 * not if many consumers share one IP address (proxy without reverseProxyIP).
 * Brute-forcing a token of 256 random bits is hopeless anyway; the limit keeps
 * the log and the database quiet.
 *
 * Logging (warning with IP address and path, never the token): with an active
 * limit only the first failed attempt of an IP address in the time window and
 * the first refused attempt; without a limit every failed attempt.
 */
final class FailedAttemptLimiter implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const DEFAULT_LIMIT = 10;
    public const DEFAULT_INTERVAL = '15 minutes';

    private const CACHE = 'ratelimiter';
    private const LIMITER_ID = 'api-export-feed';
    private const LOGGED_PREFIX = 'apiexport_limit_logged_';

    public function __construct(
        private readonly CacheManager $cacheManager,
    ) {}

    /**
     * Registers a failed attempt.
     *
     * @param int $limit Failed attempts per IP address and time window, 0 = no limit
     * @param string $interval Time window, e.g. "15 minutes"
     * @return \DateTimeImmutable|null When further attempts are accepted again if the limit is
     *                                 exceeded, null if this attempt is still within the limit
     */
    public function registerFailedAttempt(
        string $remoteAddress,
        string $path,
        bool $tokenSent,
        int $limit,
        string $interval,
    ): ?\DateTimeImmutable {
        $context = [
            'remoteAddress' => $remoteAddress,
            'path' => $path,
            'reason' => $tokenSent ? 'invalid or inactive token' : 'no token',
        ];
        if ($limit <= 0) {
            $this->logger?->warning('JSON feed: rejected request without valid token.', $context);
            return null;
        }

        $intervalSeconds = $this->intervalInSeconds($interval);
        $rateLimit = (new RateLimiterFactory(
            [
                'id' => self::LIMITER_ID,
                'policy' => 'sliding_window',
                'limit' => $limit,
                'interval' => $intervalSeconds . ' seconds',
            ],
            new CachingFrameworkStorage($this->cacheManager)
        ))->create($remoteAddress)->consume();

        if ($rateLimit->isAccepted()) {
            if ($rateLimit->getRemainingTokens() === $limit - 1) {
                $this->logger?->warning('JSON feed: rejected request without valid token.', $context);
            }
            return null;
        }

        if ($this->isFirstRefusal($remoteAddress, $intervalSeconds)) {
            $this->logger?->warning(
                'JSON feed: too many requests without valid token, further attempts are refused.',
                $context + ['limit' => $limit, 'interval' => $intervalSeconds . ' seconds']
            );
        }
        return $rateLimit->getRetryAfter();
    }

    /**
     * Whether the limit of this IP address has not yet been logged in the
     * current time window. The marker stores its expiry time itself, since
     * not every cache backend supports lifetimes.
     */
    private function isFirstRefusal(string $remoteAddress, int $intervalSeconds): bool
    {
        $now = time();
        $identifier = self::LOGGED_PREFIX . sha1($remoteAddress);
        $cache = $this->cache();
        if ((int)$cache->get($identifier) > $now) {
            return false;
        }
        $cache->set($identifier, $now + $intervalSeconds, [], $intervalSeconds);
        return true;
    }

    private function intervalInSeconds(string $interval): int
    {
        try {
            $dateInterval = \DateInterval::createFromDateString($interval);
        } catch (\Throwable) {
            $dateInterval = false;
        }
        if ($dateInterval instanceof \DateInterval) {
            $seconds = (new \DateTimeImmutable('@0'))->add($dateInterval)->getTimestamp();
            if ($seconds > 0) {
                return $seconds;
            }
        }
        return $this->intervalInSeconds(self::DEFAULT_INTERVAL);
    }

    private function cache(): FrontendInterface
    {
        return $this->cacheManager->getCache(self::CACHE);
    }
}
