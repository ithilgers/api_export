<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Tests\Unit\Feed;

use Ithilgers\ApiExport\Feed\Pagination;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PaginationTest extends UnitTestCase
{
    public static function windowDataProvider(): array
    {
        // page, limit, maxItems => offset, fetchLimit
        return [
            'first page, no cap' => [1, 10, 0, 0, 10],
            'third page, no cap' => [3, 10, 0, 20, 10],
            'cap above page' => [1, 10, 25, 0, 10],
            'cap cuts page' => [3, 10, 25, 20, 5],
            'page after cap' => [4, 10, 25, 30, 0],
            'cap smaller than limit' => [1, 100, 5, 0, 5],
        ];
    }

    #[Test]
    #[DataProvider('windowDataProvider')]
    public function offsetAndFetchLimitRespectMaxItems(
        int $page,
        int $limit,
        int $maxItems,
        int $expectedOffset,
        int $expectedFetchLimit
    ): void {
        $pagination = new Pagination($page, $limit, $maxItems);

        self::assertSame($expectedOffset, $pagination->getOffset());
        self::assertSame($expectedFetchLimit, $pagination->getFetchLimit());
    }

    #[Test]
    public function totalIsCappedByMaxItems(): void
    {
        self::assertSame(42, (new Pagination(1, 10))->capTotal(42));
        self::assertSame(25, (new Pagination(1, 10, 25))->capTotal(42));
        self::assertSame(7, (new Pagination(1, 10, 25))->capTotal(7));
    }

    #[Test]
    public function pagesAreRoundedUp(): void
    {
        $pagination = new Pagination(1, 10);

        self::assertSame(0, $pagination->countPages(0));
        self::assertSame(1, $pagination->countPages(10));
        self::assertSame(2, $pagination->countPages(11));
    }

    #[Test]
    public function withMaxItemsKeepsPageAndLimit(): void
    {
        $pagination = (new Pagination(2, 10))->withMaxItems(15);

        self::assertSame(2, $pagination->page);
        self::assertSame(10, $pagination->limit);
        self::assertSame(5, $pagination->getFetchLimit());
    }

    public static function invalidValuesDataProvider(): array
    {
        return [
            'page zero' => [0, 10, 0],
            'limit zero' => [1, 0, 0],
            'negative max items' => [1, 10, -1],
        ];
    }

    #[Test]
    #[DataProvider('invalidValuesDataProvider')]
    public function invalidValuesAreRejected(int $page, int $limit, int $maxItems): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Pagination($page, $limit, $maxItems);
    }
}
