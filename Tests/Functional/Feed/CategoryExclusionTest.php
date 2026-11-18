<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Tests\Functional\Feed;

use Ithilgers\ApiExport\Tests\Functional\AbstractFeedTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Category conjunctions of grant 1 (client TOKEN_ALL, folder 10).
 *
 * Categories of the 8 visible events: "Languages workshop" 1, "Spanish for
 * beginners" 2 (subcategory of 1) and 3, "Other event" 3, "Languages and
 * miscellaneous" 1 and 3, "Spanish with hidden category" 4 (hidden
 * subcategory of 1); "Ongoing course", "Event without category" and "Event
 * for all languages" have none.
 */
final class CategoryExclusionTest extends AbstractFeedTestCase
{
    #[Test]
    public function notOrExcludesRecordsWithAnyOfTheCategories(): void
    {
        $this->updateFeed(self::FEED_ALL_EVENTS, ['categories' => '1,3', 'category_conjunction' => 'notor']);

        self::assertSame(
            ['Ongoing course', 'Event without category', 'Spanish with hidden category', 'Event for all languages'],
            $this->eventTitles()
        );
    }

    #[Test]
    public function notAndExcludesOnlyRecordsWithAllOfTheCategories(): void
    {
        $this->updateFeed(self::FEED_ALL_EVENTS, ['categories' => '1,3', 'category_conjunction' => 'notand']);

        self::assertSame(
            [
                'Ongoing course',
                'Languages workshop',
                'Spanish for beginners',
                'Other event',
                'Event without category',
                'Spanish with hidden category',
                'Event for all languages',
            ],
            $this->eventTitles()
        );
    }

    #[Test]
    public function exclusionIncludesActiveSubcategories(): void
    {
        $this->updateFeed(self::FEED_ALL_EVENTS, ['categories' => '1', 'category_conjunction' => 'notor']);
        $withoutSubcategories = $this->eventTitles();

        $this->updateFeed(self::FEED_ALL_EVENTS, ['category_include_subcategories' => 1]);
        $withSubcategories = $this->eventTitles();

        self::assertContains('Spanish for beginners', $withoutSubcategories);
        self::assertNotContains('Spanish for beginners', $withSubcategories);
        // The hidden subcategory 4 does not count.
        self::assertContains('Spanish with hidden category', $withSubcategories);
        self::assertSame(
            ['Ongoing course', 'Other event', 'Event without category', 'Spanish with hidden category', 'Event for all languages'],
            $withSubcategories
        );
    }

    #[Test]
    public function unknownConjunctionBehavesLikeOr(): void
    {
        $this->updateFeed(self::FEED_ALL_EVENTS, ['categories' => '1,3', 'category_conjunction' => 'or']);
        $or = $this->eventTitles();

        $this->updateFeed(self::FEED_ALL_EVENTS, ['category_conjunction' => 'xor']);

        self::assertSame(['Languages workshop', 'Spanish for beginners', 'Other event', 'Languages and miscellaneous'], $or);
        self::assertSame($or, $this->eventTitles());
    }

    /**
     * @return string[]
     */
    private function eventTitles(): array
    {
        return $this->titles($this->decode($this->feedRequest('/api/v1/events', self::TOKEN_ALL)));
    }
}
