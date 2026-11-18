<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Feed;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Category filter of a grant (sys_category).
 *
 * There is one group per selected category: the category itself and – with
 * "Include subcategories" – its subcategories. A group counts as present if
 * the record has one of its categories. Like the list plugins of news and
 * sf_event_mgt, the conjunction includes or excludes records:
 *
 *   or      at least one group is present
 *   and     all groups are present
 *   notor   no group is present (records without categories remain)
 *   notand  not all groups are present
 *
 * An unknown conjunction is treated as "or". An empty filter does not
 * restrict anything. A set filter whose categories are not assigned to any
 * record, however, yields an empty list for "or" and "and".
 */
final class CategoryFilter
{
    public const CONJUNCTION_OR = 'or';
    public const CONJUNCTION_AND = 'and';
    public const CONJUNCTION_NOT_OR = 'notor';
    public const CONJUNCTION_NOT_AND = 'notand';

    private const CONJUNCTIONS = [
        self::CONJUNCTION_OR,
        self::CONJUNCTION_AND,
        self::CONJUNCTION_NOT_OR,
        self::CONJUNCTION_NOT_AND,
    ];

    private const MM_TABLE = 'sys_category_record_mm';

    /**
     * One group per selected category; the first element is the selected
     * category, followed by its subcategories, if any.
     *
     * @var list<list<int>>
     */
    public readonly array $groups;
    public readonly string $conjunction;

    /**
     * @param array<array-key, array<array-key, int|string>> $groups
     */
    public function __construct(array $groups = [], string $conjunction = self::CONJUNCTION_OR)
    {
        $normalized = [];
        foreach ($groups as $group) {
            $uids = self::positiveUnique($group);
            if ($uids !== []) {
                $normalized[] = $uids;
            }
        }
        $this->groups = $normalized;
        $this->conjunction = in_array($conjunction, self::CONJUNCTIONS, true) ? $conjunction : self::CONJUNCTION_OR;
    }

    /**
     * Reads the selected categories from a comma-separated list
     * (field "categories" of the grant), one group per category.
     */
    public static function fromList(string $list, string $conjunction = self::CONJUNCTION_OR): self
    {
        return new self(
            array_map(
                static fn(int $uid): array => [$uid],
                self::positiveUnique(GeneralUtility::intExplode(',', $list, true))
            ),
            strtolower(trim($conjunction))
        );
    }

    /**
     * Extends each group with the subcategories of its selected category.
     *
     * @param callable(int): int[] $findSubcategories returns all subcategories of a category
     */
    public function withSubcategories(callable $findSubcategories): self
    {
        return new self(
            array_map(
                static fn(array $group): array => [...$group, ...$findSubcategories($group[0])],
                $this->groups
            ),
            $this->conjunction
        );
    }

    public function isEmpty(): bool
    {
        return $this->groups === [];
    }

    /**
     * Conditions on the record: it must ("negated" false) or must not
     * ("negated" true) have one of the categories of an entry. "or" and
     * "notor" need one condition across all groups, "and" and "notand" one
     * per group.
     *
     * @return list<array{negated: bool, categories: list<int>}>
     */
    public function getConditions(): array
    {
        if ($this->isEmpty()) {
            return [];
        }
        $negated = in_array($this->conjunction, [self::CONJUNCTION_NOT_OR, self::CONJUNCTION_NOT_AND], true);
        $groups = in_array($this->conjunction, [self::CONJUNCTION_AND, self::CONJUNCTION_NOT_AND], true)
            ? $this->groups
            : [self::positiveUnique(array_merge(...$this->groups))];

        return array_map(
            static fn(array $categories): array => ['negated' => $negated, 'categories' => $categories],
            $groups
        );
    }

    /**
     * Whether one fulfilled condition is sufficient ("notand"); otherwise
     * all conditions must be fulfilled.
     */
    public function isAnyConditionSufficient(): bool
    {
        return $this->conjunction === self::CONJUNCTION_NOT_AND;
    }

    /**
     * Restricts the query to records with matching categories.
     *
     * @param string $uidField  uid column of the record in the query, e.g. "r.uid"
     * @param string $tableName Table of the record (tablenames in the MM table)
     * @param string $fieldName Category field of the record (fieldname in the MM table)
     */
    public function applyTo(QueryBuilder $queryBuilder, string $uidField, string $tableName, string $fieldName): void
    {
        $conditions = $this->getConditions();
        if ($conditions === []) {
            return;
        }

        $constraints = [];
        foreach ($conditions as $condition) {
            $subQuery = $queryBuilder->getConnection()->createQueryBuilder();
            $subQuery->getRestrictions()->removeAll();
            $subQuery->select('mm.uid_foreign')
                ->from(self::MM_TABLE, 'mm')
                ->where(
                    $subQuery->expr()->eq('mm.tablenames', $queryBuilder->createNamedParameter($tableName)),
                    $subQuery->expr()->eq('mm.fieldname', $queryBuilder->createNamedParameter($fieldName)),
                    $subQuery->expr()->in(
                        'mm.uid_local',
                        $queryBuilder->createNamedParameter($condition['categories'], Connection::PARAM_INT_ARRAY)
                    ),
                );
            // uid_foreign is never NULL, so NOT IN is safe.
            $constraints[] = $condition['negated']
                ? $queryBuilder->expr()->notIn($uidField, $subQuery->getSQL())
                : $queryBuilder->expr()->in($uidField, $subQuery->getSQL());
        }

        $queryBuilder->andWhere(
            $this->isAnyConditionSufficient()
                ? $queryBuilder->expr()->or(...$constraints)
                : $queryBuilder->expr()->and(...$constraints)
        );
    }

    /**
     * @param array<array-key, int|string> $uids
     * @return list<int> positive uids only, without duplicates, order preserved
     */
    private static function positiveUnique(array $uids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $uids),
            static fn(int $uid): bool => $uid > 0
        )));
    }
}
