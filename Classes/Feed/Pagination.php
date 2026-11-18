<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Feed;

/**
 * Paged retrieval (?page=, ?limit=) respecting the maximum number of items of
 * a grant ("Max. items", like queryLimit/limit in the list plugin).
 *
 * The maximum number of items caps the total amount, the page size only the
 * single request: with maxItems = 5 and limit = 3, page 1 returns three
 * items, page 2 returns two, total is 5.
 */
final class Pagination
{
    public function __construct(
        public readonly int $page = 1,
        public readonly int $limit = 100,
        public readonly int $maxItems = 0,
    ) {
        if ($page < 1 || $limit < 1 || $maxItems < 0) {
            throw new \InvalidArgumentException('Invalid pagination values.', 1729584000);
        }
    }

    public function withMaxItems(int $maxItems): self
    {
        return new self($this->page, $this->limit, max(0, $maxItems));
    }

    public function getOffset(): int
    {
        return ($this->page - 1) * $this->limit;
    }

    /**
     * How many items to fetch for this page (0 = none).
     */
    public function getFetchLimit(): int
    {
        if ($this->maxItems === 0) {
            return $this->limit;
        }
        return max(0, min($this->limit, $this->maxItems - $this->getOffset()));
    }

    /**
     * Total number the consumer sees (capped by the maximum number of items).
     */
    public function capTotal(int $total): int
    {
        return $this->maxItems > 0 ? min($total, $this->maxItems) : $total;
    }

    public function countPages(int $total): int
    {
        return (int)ceil($total / $this->limit);
    }
}
