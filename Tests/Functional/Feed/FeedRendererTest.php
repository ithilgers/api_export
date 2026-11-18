<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Tests\Functional\Feed;

use Ithilgers\ApiExport\Tests\Functional\AbstractFeedTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LogLevel;

final class FeedRendererTest extends AbstractFeedTestCase
{
    #[Test]
    public function eventsContainOnlyCurrentAndUpcomingVisibleEventsOfAllowedFolders(): void
    {
        $feed = $this->decode($this->feedRequest('/api/v1/events', self::TOKEN_ALL));

        self::assertSame(
            [
                'Ongoing course',
                'Languages workshop',
                'Spanish for beginners',
                'Other event',
                'Event without category',
                'Languages and miscellaneous',
                'Spanish with hidden category',
                'Event for all languages',
            ],
            $this->titles($feed)
        );
        self::assertSame(8, $feed['count']);
        self::assertSame('1.0', $feed['version']);
    }

    #[Test]
    public function eventsRestrictedToFrontendGroupsAreExcluded(): void
    {
        $feed = $this->decode($this->feedRequest('/api/v1/events', self::TOKEN_ALL));

        self::assertNotContains('Members only', $this->titles($feed));
    }

    #[Test]
    public function workspaceDraftsAreNeverDelivered(): void
    {
        $feed = $this->decode($this->feedRequest('/api/v1/events', self::TOKEN_ALL));

        self::assertNotContains('New event in workspace', $this->titles($feed));
        self::assertNotContains('Changed workshop in workspace', $this->titles($feed));
    }

    #[Test]
    public function displayModeFutureExcludesRunningEvents(): void
    {
        $this->setFeedSettings(self::FEED_ALL_EVENTS, ['displayMode' => 'future']);

        $feed = $this->decode($this->feedRequest('/api/v1/events', self::TOKEN_ALL));

        self::assertNotContains('Ongoing course', $this->titles($feed));
        self::assertContains('Languages workshop', $this->titles($feed));
    }

    #[Test]
    public function displayModePastReturnsEndedEvents(): void
    {
        $this->setFeedSettings(self::FEED_ALL_EVENTS, ['displayMode' => 'past']);

        $feed = $this->decode($this->feedRequest('/api/v1/events', self::TOKEN_ALL));

        self::assertSame(['Past languages event'], $this->titles($feed));
    }

    #[Test]
    public function displayModeAllReturnsPastAndUpcomingEvents(): void
    {
        $this->setFeedSettings(self::FEED_ALL_EVENTS, ['displayMode' => 'all']);

        $feed = $this->decode($this->feedRequest('/api/v1/events', self::TOKEN_ALL));

        self::assertSame(9, $feed['total']);
        self::assertContains('Past languages event', $this->titles($feed));
    }

    #[Test]
    public function orderDirectionCanBeConfiguredPerFeed(): void
    {
        $this->setFeedSettings(self::FEED_ALL_EVENTS, ['orderField' => 'startdate', 'orderDirection' => 'desc']);

        $titles = $this->titles($this->decode($this->feedRequest('/api/v1/events', self::TOKEN_ALL)));

        self::assertSame('Event for all languages', $titles[0]);
        self::assertSame('Ongoing course', $titles[array_key_last($titles)]);
    }

    #[Test]
    public function orderFieldCanBeConfiguredPerFeed(): void
    {
        $this->setFeedSettings(self::FEED_ALL_EVENTS, ['orderField' => 'title', 'orderDirection' => 'asc']);

        $titles = $this->titles($this->decode($this->feedRequest('/api/v1/events', self::TOKEN_ALL)));

        // Only check unambiguous positions – the finer ordering depends on the DB collation.
        self::assertSame('Event for all languages', $titles[0]);
        self::assertSame('Spanish with hidden category', $titles[array_key_last($titles)]);
    }

    #[Test]
    public function unknownOrderFieldFallsBackToStartdate(): void
    {
        $this->setFeedSettings(self::FEED_ALL_EVENTS, ['orderField' => 'uid; DROP TABLE pages']);

        $titles = $this->titles($this->decode($this->feedRequest('/api/v1/events', self::TOKEN_ALL)));

        self::assertSame('Ongoing course', $titles[0]);
    }

    #[Test]
    public function eventItemHasExpectedShape(): void
    {
        $feed = $this->decode($this->feedRequest('/api/v1/events', self::TOKEN_ALL));
        $items = array_column($feed['items'], null, 'uid');
        $item = $items[1];

        self::assertSame(1, $item['uid']);
        self::assertSame('For beginners', $item['teaser']);
        self::assertSame('Town Hall', $item['location']);
        self::assertSame('Sampletown', $item['city']);
        self::assertTrue($item['registrationRequired']);
        self::assertSame(2115194400, strtotime($item['start']));
        self::assertSame(2115216000, strtotime($item['end']));
        self::assertNull($items[2]['end']);
        // Neither a detail page mapping nor a global detailPid -> no link.
        self::assertSame('', $item['link']);
    }

    #[Test]
    public function categoryFilterReturnsOnlyEventsWithThatCategory(): void
    {
        // Client "Languages": category 1, OR, without subcategories, folder 10 + 1 level.
        $feed = $this->decode($this->feedRequest('/api/v1/events', self::TOKEN_LANGUAGES));

        self::assertSame(
            ['Languages workshop', 'Languages in subfolder', 'Languages and miscellaneous'],
            $this->titles($feed)
        );
    }

    #[Test]
    public function categoryFilterCanIncludeActiveSubcategories(): void
    {
        // "Spanish" is a subcategory of 1; the hidden subcategory 4 does not count.
        $feed = $this->decode($this->feedRequest('/api/v1/events', self::TOKEN_SUBCATEGORIES));

        self::assertSame(
            ['Languages workshop', 'Spanish for beginners', 'Languages and miscellaneous'],
            $this->titles($feed)
        );
    }

    #[Test]
    public function categoryFilterWithAndConjunctionRequiresAllCategories(): void
    {
        $feed = $this->decode($this->feedRequest('/api/v1/events', self::TOKEN_AND));

        self::assertSame(['Languages and miscellaneous'], $this->titles($feed));
    }

    #[Test]
    public function andConjunctionWithSubcategoriesRequiresEachSelectedCategoryOrOneOfItsChildren(): void
    {
        // Categories 1 and 3; "Spanish for beginners" has 2 (subcategory of 1) and 3.
        $this->updateFeed(5, ['category_include_subcategories' => 1]);

        $feed = $this->decode($this->feedRequest('/api/v1/events', self::TOKEN_AND));

        self::assertSame(['Spanish for beginners', 'Languages and miscellaneous'], $this->titles($feed));
    }

    #[Test]
    public function categoryFilterAppliesToNews(): void
    {
        $feed = $this->decode($this->feedRequest('/api/v1/news', self::TOKEN_NEWS_ONLY));

        self::assertSame(['Languages news'], $this->titles($feed));
    }

    #[Test]
    public function newsAreSortedByDateAndExcludeArchived(): void
    {
        $feed = $this->decode($this->feedRequest('/api/v1/news', self::TOKEN_ALL));

        // "Internal news" is restricted to a frontend group.
        self::assertSame(['Languages news', 'Other news', 'News for all languages'], $this->titles($feed));
        // Detail page of the grant (without a per-folder mapping).
        self::assertStringStartsWith(self::BASE_URL . '/news-detail', $feed['items'][0]['link']);
    }

    #[Test]
    public function newsArchiveRestrictionCanBeConfigured(): void
    {
        $this->setFeedSettings(2, ['archiveRestriction' => 'archived']);

        $feed = $this->decode($this->feedRequest('/api/v1/news', self::TOKEN_ALL));

        self::assertSame(['Archived languages news'], $this->titles($feed));
    }

    #[Test]
    public function detailLinksAreResolvedPerFolderAndInheritedBySubfolders(): void
    {
        $feed = $this->decode($this->feedRequest('/api/v1/events', self::TOKEN_LANGUAGES));

        foreach ($feed['items'] as $item) {
            self::assertStringStartsWith(self::BASE_URL . '/event-detail', $item['link'], $item['title']);
            self::assertStringContainsString('tx_sfeventmgt_pieventdetail', urldecode($item['link']));
        }
    }

    #[Test]
    public function clientWithoutFoldersGetsEmptyFeed(): void
    {
        $feed = $this->decode($this->feedRequest('/api/v1/events', self::TOKEN_NO_PAGES));

        self::assertSame(0, $feed['count']);
        self::assertSame([], $feed['items']);
    }

    #[Test]
    public function maxItemsLimitsTheNumberOfItems(): void
    {
        $this->updateFeed(self::FEED_ALL_EVENTS, ['max_items' => 2]);

        $feed = $this->decode($this->feedRequest('/api/v1/events', self::TOKEN_ALL));

        self::assertSame(['Ongoing course', 'Languages workshop'], $this->titles($feed));
        self::assertSame(2, $feed['total']);
    }

    #[Test]
    public function providerErrorIsReportedAsJsonServerError(): void
    {
        // Provokes an SQL error in the event provider (join on a missing table).
        $response = $this->withTableUnavailable(
            'tx_sfeventmgt_domain_model_location',
            fn() => $this->feedRequest('/api/v1/events', self::TOKEN_ALL)
        );

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Internal Server Error', $this->decode($response)['error']);
        self::assertStringNotContainsString('tx_sfeventmgt', (string)$response->getBody());
        $this->assertProtectionHeaders($response);

        $errors = $this->logRecords(LogLevel::ERROR);
        self::assertCount(1, $errors);
        self::assertSame('events', $errors[0]->getData()['resource']);
        self::assertInstanceOf(\Throwable::class, $errors[0]->getData()['exception']);
    }
}
