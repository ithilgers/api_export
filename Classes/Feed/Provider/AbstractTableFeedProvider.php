<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Feed\Provider;

use Ithilgers\ApiExport\Feed\FeedDemand;
use Ithilgers\ApiExport\Feed\FeedItem;
use Ithilgers\ApiExport\Feed\FeedProviderInterface;
use Ithilgers\ApiExport\Feed\FeedResult;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\EndTimeRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendGroupRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\StartTimeRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;

/**
 * Base class for providers that deliver records of a TCA table.
 *
 * Takes care of everything that is the same for every table: visibility as in
 * a public list for visitors who are not logged in (deleted, hidden,
 * start/stop, frontend groups, live workspace only), default language plus
 * "all languages", storage folders, category filter, ordering, total count
 * and pagination.
 *
 * A concrete provider only supplies the table, category field, ordering and
 * the item mapping; if needed, its own conditions (addConstraints) and
 * joins (addSelect).
 */
abstract class AbstractTableFeedProvider implements FeedProviderInterface
{
    /** Alias of the main table in all queries. */
    protected const ALIAS = 'r';

    public function __construct(
        protected readonly ConnectionPool $connectionPool,
    ) {}

    abstract protected function getTableName(): string;

    /**
     * Category field of the table (sys_category), or null if the table
     * has no categories.
     */
    abstract protected function getCategoryField(): ?string;

    /**
     * @param array<string, mixed> $settings Settings of the grant
     * @return array{0: string, 1: string} Allowed order field and direction (ASC|DESC)
     */
    abstract protected function getOrdering(array $settings): array;

    /**
     * @param array<string, mixed> $record Record from addSelect()
     * @return array<string, mixed> JSON-serializable item
     */
    abstract protected function buildItem(array $record, FeedDemand $demand): array;

    /**
     * Resource-specific conditions, e.g. time period or archive.
     */
    protected function addConstraints(QueryBuilder $queryBuilder, FeedDemand $demand): void {}

    /**
     * Columns (and joins, if any) of the item query. Default: all columns.
     */
    protected function addSelect(QueryBuilder $queryBuilder): void
    {
        $queryBuilder->select(self::ALIAS . '.*');
    }

    /**
     * Timestamp as an ISO 8601 date, null for "not set" (0).
     */
    protected function isoDate(int $timestamp): ?string
    {
        return $timestamp > 0 ? date('c', $timestamp) : null;
    }

    public function findItems(FeedDemand $demand): FeedResult
    {
        // Table not available (source extension not installed) or
        // category filter not applicable -> deliver nothing.
        if ($demand->pageIds === []
            || !isset($GLOBALS['TCA'][$this->getTableName()])
            || (!$demand->categoryFilter->isEmpty() && $this->getCategoryField() === null)
        ) {
            return new FeedResult([], 0);
        }

        $total = (int)$this->createQuery($demand)
            ->count(self::ALIAS . '.uid')
            ->executeQuery()
            ->fetchOne();

        if ($demand->limit <= 0 || $demand->offset >= $total) {
            return new FeedResult([], $total);
        }

        [$orderField, $orderDirection] = $this->getOrdering($demand->settings);
        $queryBuilder = $this->createQuery($demand);
        $this->addSelect($queryBuilder);
        $queryBuilder
            ->orderBy(self::ALIAS . '.' . $orderField, $orderDirection)
            ->addOrderBy(self::ALIAS . '.uid', 'ASC')
            ->setFirstResult($demand->offset)
            ->setMaxResults($demand->limit);

        $items = [];
        foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $record) {
            $items[] = new FeedItem($this->buildItem($record, $demand), $record);
        }
        return new FeedResult($items, $total);
    }

    /**
     * Query with all conditions, but without columns and ordering –
     * common basis for the total count and the item query.
     */
    private function createQuery(FeedDemand $demand): QueryBuilder
    {
        $table = $this->getTableName();
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new HiddenRestriction())
            ->add(new StartTimeRestriction())
            ->add(new EndTimeRestriction())
            // Always filter as an anonymous visitor, regardless of any
            // frontend login sent along with the request.
            ->add(new FrontendGroupRestriction([0, -1]))
            ->add(new WorkspaceRestriction(0));

        $queryBuilder
            ->from($table, self::ALIAS)
            ->where(
                $queryBuilder->expr()->in(
                    self::ALIAS . '.pid',
                    $queryBuilder->createNamedParameter($demand->pageIds, Connection::PARAM_INT_ARRAY)
                )
            );

        $languageField = (string)($GLOBALS['TCA'][$table]['ctrl']['languageField'] ?? '');
        if ($languageField !== '') {
            // Default language and "all languages"
            $queryBuilder->andWhere(
                $queryBuilder->expr()->in(
                    self::ALIAS . '.' . $languageField,
                    $queryBuilder->createNamedParameter([0, -1], Connection::PARAM_INT_ARRAY)
                )
            );
        }

        $categoryField = $this->getCategoryField();
        if ($categoryField !== null) {
            $demand->categoryFilter->applyTo($queryBuilder, self::ALIAS . '.uid', $table, $categoryField);
        }

        $this->addConstraints($queryBuilder, $demand);
        return $queryBuilder;
    }
}
