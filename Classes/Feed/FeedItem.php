<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Feed;

/**
 * A feed item: the output data plus the record it originates from
 * (for ModifyFeedItemEvent).
 */
final class FeedItem
{
    /**
     * @param array<string, mixed> $data JSON-serializable output
     * @param array<string, mixed> $record Underlying record
     */
    public function __construct(
        public readonly array $data,
        public readonly array $record,
    ) {}
}
