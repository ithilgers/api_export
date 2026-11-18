<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Feed;

/**
 * Everything a provider needs for a request – assembled from the client's
 * grant and the pagination parameters.
 *
 * New parameters are added here without changing the signature of
 * FeedProviderInterface::findItems().
 */
final class FeedDemand
{
    /**
     * @param string $resource Resource name, e.g. "events"
     * @param int[] $pageIds Storage folders of the grant (incl. subfolders)
     * @param CategoryFilter $categoryFilter Category restriction of the grant (empty = none)
     * @param array<string, mixed> $settings Resource-specific settings (FlexForm of the grant)
     * @param DetailLinkBuilder $detailLinks Builds absolute links to the detail page
     * @param int $offset First item (0-based)
     * @param int $limit Maximum number of items for this request (0 = only determine the total)
     */
    public function __construct(
        public readonly string $resource,
        public readonly array $pageIds,
        public readonly CategoryFilter $categoryFilter,
        public readonly array $settings,
        public readonly DetailLinkBuilder $detailLinks,
        public readonly int $offset,
        public readonly int $limit,
    ) {}
}
