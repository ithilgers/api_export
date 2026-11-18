<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Tests\Unit\Feed;

use Ithilgers\ApiExport\Feed\CategoryFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class CategoryFilterTest extends UnitTestCase
{
    public static function listDataProvider(): array
    {
        return [
            'empty' => ['', []],
            'single' => ['5', [5]],
            'several' => ['5,7,9', [5, 7, 9]],
            'whitespace and duplicates' => [' 5, 7 ,5', [5, 7]],
            'invalid and non-positive values' => ['0,-3,abc,8', [8]],
        ];
    }

    /**
     * @param int[] $expected
     */
    #[Test]
    #[DataProvider('listDataProvider')]
    public function fromListCreatesOneGroupPerCategory(string $list, array $expected): void
    {
        self::assertSame(
            array_map(static fn(int $uid): array => [$uid], $expected),
            CategoryFilter::fromList($list)->groups
        );
    }

    #[Test]
    public function withSubcategoriesExtendsEachGroupWithTheChildrenOfItsCategory(): void
    {
        $children = [1 => [2, 4], 3 => []];
        $filter = CategoryFilter::fromList('1,3', 'and')
            ->withSubcategories(static fn(int $uid): array => $children[$uid]);

        self::assertSame([[1, 2, 4], [3]], $filter->groups);
        self::assertSame(CategoryFilter::CONJUNCTION_AND, $filter->conjunction);
    }

    #[Test]
    public function withSubcategoriesIgnoresInvalidAndDuplicateChildren(): void
    {
        $filter = CategoryFilter::fromList('1')
            ->withSubcategories(static fn(int $uid): array => [1, 0, 2, 2]);

        self::assertSame([[1, 2]], $filter->groups);
    }

    #[Test]
    public function filterWithoutCategoriesIsEmpty(): void
    {
        self::assertTrue(CategoryFilter::fromList('')->isEmpty());
        self::assertFalse(CategoryFilter::fromList('3')->isEmpty());
    }

    public static function conjunctionDataProvider(): array
    {
        return [
            'or' => ['or', CategoryFilter::CONJUNCTION_OR],
            'and' => ['and', CategoryFilter::CONJUNCTION_AND],
            'notor' => ['notor', CategoryFilter::CONJUNCTION_NOT_OR],
            'notand' => ['notand', CategoryFilter::CONJUNCTION_NOT_AND],
            'upper case and' => [' AND ', CategoryFilter::CONJUNCTION_AND],
            'upper case notand' => ['NOTAND', CategoryFilter::CONJUNCTION_NOT_AND],
            'empty falls back to or' => ['', CategoryFilter::CONJUNCTION_OR],
            'unknown falls back to or' => ['xor', CategoryFilter::CONJUNCTION_OR],
            'with a space falls back to or' => ['not or', CategoryFilter::CONJUNCTION_OR],
        ];
    }

    #[Test]
    #[DataProvider('conjunctionDataProvider')]
    public function conjunctionIsNormalized(string $conjunction, string $expected): void
    {
        self::assertSame($expected, CategoryFilter::fromList('1', $conjunction)->conjunction);
    }

    public static function conditionsDataProvider(): array
    {
        // Groups [1, 2] (category 1 with subcategory 2) and [3].
        return [
            'or: one condition across all groups' => [
                'or',
                [['negated' => false, 'categories' => [1, 2, 3]]],
                false,
            ],
            'and: one condition per group, all required' => [
                'and',
                [['negated' => false, 'categories' => [1, 2]], ['negated' => false, 'categories' => [3]]],
                false,
            ],
            'notor: one negated condition across all groups' => [
                'notor',
                [['negated' => true, 'categories' => [1, 2, 3]]],
                false,
            ],
            'notand: one negated condition per group, one is sufficient' => [
                'notand',
                [['negated' => true, 'categories' => [1, 2]], ['negated' => true, 'categories' => [3]]],
                true,
            ],
            'unknown: like or' => [
                'xor',
                [['negated' => false, 'categories' => [1, 2, 3]]],
                false,
            ],
        ];
    }

    #[Test]
    #[DataProvider('conditionsDataProvider')]
    public function conditionsDependOnTheConjunction(string $conjunction, array $expected, bool $anySufficient): void
    {
        $filter = CategoryFilter::fromList('1,3', $conjunction)
            ->withSubcategories(static fn(int $uid): array => $uid === 1 ? [2] : []);

        self::assertSame($expected, $filter->getConditions());
        self::assertSame($anySufficient, $filter->isAnyConditionSufficient());
    }

    #[Test]
    public function emptyFilterHasNoConditions(): void
    {
        self::assertSame([], CategoryFilter::fromList('', 'notor')->getConditions());
    }
}
