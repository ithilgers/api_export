<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Http;

use Ithilgers\ApiExport\Feed\Pagination;

/**
 * Authorized feed request: what the middleware has validated and passes on
 * to the FeedRenderer.
 */
final class FeedRequest
{
    /**
     * @param array<string, mixed> $client Authenticated client (uid, title, …), without token data
     * @param string $resource Requested resource, e.g. "events"
     * @param array<string, mixed> $feed The client's grant for this resource
     */
    public function __construct(
        public readonly array $client,
        public readonly string $resource,
        public readonly array $feed,
        public readonly Pagination $pagination,
    ) {}
}
