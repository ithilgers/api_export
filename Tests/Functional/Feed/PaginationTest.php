<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Tests\Functional\Feed;

use Ithilgers\ApiExport\Tests\Functional\AbstractFeedTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Client TOKEN_ALL sees 8 events (see FeedRendererTest).
 */
final class PaginationTest extends AbstractFeedTestCase
{
    #[Test]
    public function withoutParametersTheFirstPageWithDefaultLimitIsReturned(): void
    {
        $feed = $this->decode($this->feedRequest('/api/v1/events', self::TOKEN_ALL));

        self::assertSame(8, $feed['total']);
        self::assertSame(1, $feed['page']);
        self::assertSame(1, $feed['pages']);
        self::assertSame(100, $feed['limit']);
        self::assertSame(8, $feed['count']);
    }

    #[Test]
    public function limitSplitsItemsIntoPages(): void
    {
        $first = $this->decode($this->feedRequest('/api/v1/events?limit=3', self::TOKEN_ALL));
        $last = $this->decode($this->feedRequest('/api/v1/events?limit=3&page=3', self::TOKEN_ALL));

        self::assertSame(['Ongoing course', 'Languages workshop', 'Spanish for beginners'], $this->titles($first));
        self::assertSame(3, $first['pages']);
        self::assertSame(8, $first['total']);
        self::assertSame(['Spanish with hidden category', 'Event for all languages'], $this->titles($last));
        self::assertSame(3, $last['page']);
        self::assertSame(2, $last['count']);
    }

    #[Test]
    public function pagesTogetherContainEveryItemExactlyOnce(): void
    {
        $all = $this->titles($this->decode($this->feedRequest('/api/v1/events', self::TOKEN_ALL)));

        $paged = [];
        for ($page = 1; $page <= 3; $page++) {
            $feed = $this->decode($this->feedRequest('/api/v1/events?limit=3&page=' . $page, self::TOKEN_ALL));
            $paged = array_merge($paged, $this->titles($feed));
        }

        self::assertSame($all, $paged);
    }

    #[Test]
    public function pageBeyondTheLastIsEmpty(): void
    {
        $feed = $this->decode($this->feedRequest('/api/v1/events?limit=3&page=4', self::TOKEN_ALL));

        self::assertSame([], $feed['items']);
        self::assertSame(8, $feed['total']);
        self::assertSame(3, $feed['pages']);
    }

    #[Test]
    public function maxItemsCapsTotalAcrossPages(): void
    {
        $this->updateFeed(self::FEED_ALL_EVENTS, ['max_items' => 5]);

        $second = $this->decode($this->feedRequest('/api/v1/events?limit=3&page=2', self::TOKEN_ALL));
        $third = $this->decode($this->feedRequest('/api/v1/events?limit=3&page=3', self::TOKEN_ALL));

        self::assertSame(['Other event', 'Event without category'], $this->titles($second));
        self::assertSame(5, $second['total']);
        self::assertSame(2, $second['pages']);
        self::assertSame([], $third['items']);
    }
}
