<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Feed\Provider;

use Ithilgers\ApiExport\Feed\FeedDemand;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Delivers events from derhansen/sf_event_mgt as feed items.
 *
 * The display modes correspond to those of the sf_event_mgt list plugin
 * ("displayMode" setting of the grant).
 */
final class EventFeedProvider extends AbstractTableFeedProvider
{
    private const TABLE_LOCATION = 'tx_sfeventmgt_domain_model_location';
    private const ORDER_FIELDS = ['startdate', 'enddate', 'title', 'crdate', 'tstamp', 'sorting'];

    public function getResource(): string
    {
        return 'events';
    }

    protected function getTableName(): string
    {
        return 'tx_sfeventmgt_domain_model_event';
    }

    protected function getCategoryField(): ?string
    {
        return 'category';
    }

    protected function getOrdering(array $settings): array
    {
        $field = (string)($settings['orderField'] ?? 'startdate');
        $direction = strtoupper((string)($settings['orderDirection'] ?? 'asc')) === 'DESC' ? 'DESC' : 'ASC';
        return [in_array($field, self::ORDER_FIELDS, true) ? $field : 'startdate', $direction];
    }

    protected function addSelect(QueryBuilder $queryBuilder): void
    {
        $queryBuilder
            ->select(self::ALIAS . '.*', 'l.title AS location_title', 'l.city AS location_city')
            ->leftJoin(self::ALIAS, self::TABLE_LOCATION, 'l', self::ALIAS . '.location = l.uid');
    }

    protected function addConstraints(QueryBuilder $queryBuilder, FeedDemand $demand): void
    {
        $displayMode = (string)($demand->settings['displayMode'] ?? 'current_future');
        if (!in_array($displayMode, ['future', 'current_future', 'past'], true)) {
            // "all" (or unknown): no time-based filter
            return;
        }

        $now = $queryBuilder->createNamedParameter($GLOBALS['EXEC_TIME'] ?? time(), Connection::PARAM_INT);
        $startdate = self::ALIAS . '.startdate';
        $enddate = self::ALIAS . '.enddate';

        $queryBuilder->andWhere(match ($displayMode) {
            'future' => $queryBuilder->expr()->gt($startdate, $now),
            // Also ongoing events (started, but not yet finished).
            'current_future' => $queryBuilder->expr()->or(
                $queryBuilder->expr()->gt($startdate, $now),
                $queryBuilder->expr()->gte($enddate, $now),
            ),
            'past' => $queryBuilder->expr()->and(
                $queryBuilder->expr()->gt($enddate, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->lte($enddate, $now),
            ),
        });
    }

    protected function buildItem(array $record, FeedDemand $demand): array
    {
        return [
            'uid' => (int)$record['uid'],
            'title' => (string)$record['title'],
            'teaser' => (string)$record['teaser'],
            'start' => $this->isoDate((int)$record['startdate']),
            'end' => $this->isoDate((int)$record['enddate']),
            'location' => $record['location_title'] !== null ? (string)$record['location_title'] : null,
            'city' => $record['location_city'] !== null && $record['location_city'] !== '' ? (string)$record['location_city'] : null,
            'registrationRequired' => (bool)$record['enable_registration'],
            'link' => $demand->detailLinks->build((int)$record['pid'], [
                'tx_sfeventmgt_pieventdetail' => [
                    'controller' => 'Event',
                    'action' => 'detail',
                    'event' => (int)$record['uid'],
                ],
            ]),
        ];
    }
}
