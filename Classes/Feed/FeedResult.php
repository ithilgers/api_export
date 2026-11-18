<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Feed;

/**
 * Result of a provider request: the items of the requested page and the
 * total number of all matching items.
 */
final class FeedResult
{
    /**
     * @param FeedItem[] $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
    ) {}
}
