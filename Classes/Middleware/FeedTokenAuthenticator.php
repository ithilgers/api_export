<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Middleware;

use Ithilgers\ApiExport\Feed\FeedProviderRegistry;
use Ithilgers\ApiExport\Feed\FeedRenderer;
use Ithilgers\ApiExport\Feed\Pagination;
use Ithilgers\ApiExport\Http\FeedErrorResponse;
use Ithilgers\ApiExport\Http\FeedRequest;
use Ithilgers\ApiExport\Http\ProtectionHeaders;
use Ithilgers\ApiExport\Security\ClientToken;
use Ithilgers\ApiExport\Security\FailedAttemptLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Http\ImmediateResponseException;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Routing\SiteRouteResult;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Detects, protects and answers feed requests at /{basePath}/{resource}.
 *
 * Sits right after the site resolver and before the frontend and backend
 * user authentication, the base redirect and the page resolver. Feed requests
 * are answered here and never reach the rest of the frontend stack; all other
 * requests are passed on unchanged.
 *
 * A request is a feed request if the remaining path of the site routing (the
 * path below the base of the site and its language) is the base path or
 * starts with it, followed by a slash. This works with and without a language
 * prefix and in subdirectory installations.
 *
 * Flow:
 *  - Allow GET/HEAD only.
 *  - Check the token (HTTP header) against the client table (one record per
 *    consumer, can be disabled or time-limited individually). Requests
 *    without a valid token are counted per IP address; above the limit they
 *    are answered with 429 instead of 401 (see FailedAttemptLimiter).
 *  - Only then check whether the requested resource exists and the client
 *    has an active grant for it – without a valid token, nobody learns which
 *    resources exist.
 *  - Validate pagination (?page=, ?limit=).
 *  - Let the FeedRenderer generate the feed.
 *
 * Every response is JSON and carries the protective headers. Unexpected
 * errors are logged and answered with a JSON 500; an intentional abort with a
 * custom response (e.g. by an event listener) is delivered as it is.
 */
final class FeedTokenAuthenticator implements MiddlewareInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const TABLE = 'tx_apiexport_domain_model_client';
    private const FEED_TABLE = 'tx_apiexport_domain_model_feed';
    private const ALLOWED_METHODS = ['GET', 'HEAD'];
    private const DEFAULT_BASE_PATH = 'api/v1';
    private const DEFAULT_LIMIT = 100;
    private const DEFAULT_MAX_LIMIT = 500;
    // Write "Last used" at most once per minute.
    private const TOUCH_INTERVAL = 60;

    public function __construct(
        private readonly FeedProviderRegistry $registry,
        private readonly FeedRenderer $renderer,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly ConnectionPool $connectionPool,
        private readonly FailedAttemptLimiter $failedAttemptLimiter,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $config = $this->configuration();
        $resourcePath = $this->resourcePath($request, trim((string)($config['basePath'] ?? self::DEFAULT_BASE_PATH), '/'));
        if ($resourcePath === null) {
            return $handler->handle($request);
        }

        $response = ProtectionHeaders::apply($this->respond($request, $config, $resourcePath));
        if ($request->getMethod() === 'HEAD') {
            $response = $response->withBody(new Stream('php://temp', 'r+'));
        }
        return $response;
    }

    /**
     * Path below the base path for feed requests ("events", "" or e.g.
     * "events/123"), null for all other requests.
     */
    private function resourcePath(ServerRequestInterface $request, string $basePath): ?string
    {
        $routing = $request->getAttribute('routing');
        if ($basePath === '' || !$routing instanceof SiteRouteResult || !$routing->getSite() instanceof Site) {
            return null;
        }
        $tail = ltrim($routing->getTail(), '/');
        if ($tail === $basePath) {
            return '';
        }
        if (!str_starts_with($tail, $basePath . '/')) {
            return null;
        }
        return trim(substr($tail, strlen($basePath) + 1), '/');
    }

    private function respond(ServerRequestInterface $request, array $config, string $resourcePath): ResponseInterface
    {
        if (!in_array($request->getMethod(), self::ALLOWED_METHODS, true)) {
            return new FeedErrorResponse(
                405,
                'Method Not Allowed',
                'Only GET and HEAD requests are supported.',
                ['Allow' => implode(', ', self::ALLOWED_METHODS)]
            );
        }

        $feedRequest = null;
        try {
            $feedRequest = $this->authorize($request, $config, $resourcePath);
            if ($feedRequest instanceof ResponseInterface) {
                return $feedRequest;
            }
            return $this->renderer->render($feedRequest, $request);
        } catch (ImmediateResponseException $e) {
            // Intentional abort with a custom response, e.g. from an event listener.
            return $e->getResponse();
        } catch (\Throwable $e) {
            $this->logger?->error('JSON feed: the request could not be processed.', [
                'resource' => $feedRequest instanceof FeedRequest ? $feedRequest->resource : $resourcePath,
                'client' => $feedRequest instanceof FeedRequest ? (int)($feedRequest->client['uid'] ?? 0) : 0,
                'exception' => $e,
            ]);
            return new FeedErrorResponse(500, 'Internal Server Error', 'The feed could not be generated.');
        }
    }

    /**
     * Validates token, resource, grant and pagination.
     *
     * @return FeedRequest|ResponseInterface Validated request or error response
     */
    private function authorize(
        ServerRequestInterface $request,
        array $config,
        string $resource,
    ): FeedRequest|ResponseInterface {
        // Authentication.
        $headerName = (string)($config['headerName'] ?? 'X-Feed-Token');
        $providedToken = trim($request->getHeaderLine($headerName));
        $client = $providedToken === '' ? null : $this->findValidClient($providedToken);

        if ($client === null) {
            $retryAfter = $this->failedAttemptLimiter->registerFailedAttempt(
                $this->remoteAddress($request),
                $request->getUri()->getPath(),
                $providedToken !== '',
                max(0, (int)($config['failedAttemptsLimit'] ?? FailedAttemptLimiter::DEFAULT_LIMIT)),
                (string)($config['failedAttemptsInterval'] ?? FailedAttemptLimiter::DEFAULT_INTERVAL),
            );
            if ($retryAfter !== null) {
                return new FeedErrorResponse(
                    429,
                    'Too Many Requests',
                    'Too many requests without a valid feed token. Try again later.',
                    ['Retry-After' => (string)max(1, $retryAfter->getTimestamp() - time())]
                );
            }
            return new FeedErrorResponse(401, 'Unauthorized', 'Missing or invalid feed token.');
        }

        // A resource name never contains a slash: /api/v1/events/123 is unknown.
        if ($resource === '' || str_contains($resource, '/') || !$this->registry->has($resource)) {
            return new FeedErrorResponse(404, 'Not Found', 'Unknown feed resource.');
        }

        // The client's grant for this resource.
        $feed = $this->findFeed((int)$client['uid'], $resource);
        if ($feed === null) {
            return new FeedErrorResponse(
                403,
                'Forbidden',
                'This client is not allowed to access the requested resource.'
            );
        }

        $queryParams = $request->getQueryParams();
        $maxLimit = max(1, (int)($config['maxLimit'] ?? self::DEFAULT_MAX_LIMIT));
        $defaultLimit = min($maxLimit, max(1, (int)($config['defaultLimit'] ?? self::DEFAULT_LIMIT)));
        $page = $this->positiveIntParameter($queryParams, 'page', 1);
        $limit = $this->positiveIntParameter($queryParams, 'limit', $defaultLimit);
        if ($page === null || $limit === null || $limit > $maxLimit) {
            return new FeedErrorResponse(
                400,
                'Bad Request',
                sprintf('"page" must be a positive integer and "limit" an integer between 1 and %d.', $maxLimit)
            );
        }

        $now = (int)($GLOBALS['EXEC_TIME'] ?? time());
        if ($now - (int)($client['last_used'] ?? 0) >= self::TOUCH_INTERVAL) {
            $this->touch((int)$client['uid'], $now);
        }

        return new FeedRequest($client, $resource, $feed, new Pagination($page, $limit));
    }

    /**
     * Extension configuration; the defaults apply if it cannot be read.
     *
     * @return array<string, mixed>
     */
    private function configuration(): array
    {
        try {
            $config = $this->extensionConfiguration->get('api_export');
        } catch (\Throwable) {
            return [];
        }
        return is_array($config) ? $config : [];
    }

    private function remoteAddress(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        return $normalizedParams instanceof NormalizedParams ? $normalizedParams->getRemoteAddress() : '';
    }

    /**
     * Integer > 0 from the query string; $default if not given,
     * null for an invalid value.
     */
    private function positiveIntParameter(array $queryParams, string $name, int $default): ?int
    {
        if (!array_key_exists($name, $queryParams)) {
            return $default;
        }
        $value = $queryParams[$name];
        if (!is_string($value) || !ctype_digit($value) || strlen($value) > 9 || (int)$value < 1) {
            return null;
        }
        return (int)$value;
    }

    /**
     * The client's grant for the resource, null if there is none or it is
     * deactivated.
     *
     * The backend offers each resource only once per client. Should there
     * still be several grants, the first one by sorting decides – also if it
     * is deactivated, so that deactivating a grant always revokes access.
     */
    private function findFeed(int $clientUid, string $resource): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::FEED_TABLE);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());

        $row = $queryBuilder
            ->select('*')
            ->from(self::FEED_TABLE)
            ->where(
                $queryBuilder->expr()->eq('client', $queryBuilder->createNamedParameter($clientUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('resource', $queryBuilder->createNamedParameter($resource)),
            )
            ->orderBy('sorting')
            ->addOrderBy('uid')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false || (bool)$row['hidden'] ? null : $row;
    }

    /**
     * Finds the valid client for the token: exists, active (not hidden),
     * not deleted and within its validity period.
     *
     * Values that do not have the format of a generated token are rejected
     * without a database query. Otherwise the client is looked up by the hash
     * of the token; the subsequent comparison is what decides.
     */
    private function findValidClient(string $token): ?array
    {
        if (!ClientToken::isWellFormed($token)) {
            return null;
        }
        $hash = ClientToken::hash($token);
        $now = $GLOBALS['EXEC_TIME'] ?? time();

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new HiddenRestriction());

        $row = $queryBuilder
            ->select('uid', 'title', 'token_hash', 'last_used')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'token_hash',
                    $queryBuilder->createNamedParameter($hash)
                ),
                $queryBuilder->expr()->lte(
                    'starttime',
                    $queryBuilder->createNamedParameter($now, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq(
                        'endtime',
                        $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                    ),
                    $queryBuilder->expr()->gt(
                        'endtime',
                        $queryBuilder->createNamedParameter($now, Connection::PARAM_INT)
                    )
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false || !hash_equals((string)$row['token_hash'], $hash)) {
            return null;
        }
        // Do not pass the hash on.
        unset($row['token_hash']);
        return $row;
    }

    /**
     * Records the time of last use (best effort, errors are ignored).
     */
    private function touch(int $uid, int $now): void
    {
        try {
            $this->connectionPool
                ->getConnectionForTable(self::TABLE)
                ->update(self::TABLE, ['last_used' => $now], ['uid' => $uid]);
        } catch (\Throwable) {
            // Statistics are optional; an error must not block the feed.
        }
    }
}
