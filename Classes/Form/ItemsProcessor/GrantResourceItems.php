<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Form\ItemsProcessor;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * itemsProcFunc of the field "resource" of a grant: offers each resource only
 * once per client.
 *
 * Resources for which the client already has a saved grant (active or not)
 * are removed from the selection; the grant's own value always remains.
 * Grants that are added but not yet saved are not known here – should a
 * client still get two grants for the same resource, the first one by
 * sorting applies (see FeedTokenAuthenticator).
 */
final class GrantResourceItems
{
    private const TABLE = 'tx_apiexport_domain_model_feed';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array{items: array, row: array, inlineParentUid?: int|string|null} $params
     */
    public function removeUsedResources(array &$params): void
    {
        $row = $params['row'] ?? [];
        $clientUid = (int)($params['inlineParentUid'] ?? 0) ?: (int)($row['client'] ?? 0);
        if ($clientUid <= 0) {
            return;
        }

        $ownUid = (int)($row['uid'] ?? 0);
        $ownValues = array_map('strval', (array)($row['resource'] ?? []));
        $used = array_diff($this->resourcesOfClient($clientUid, $ownUid), $ownValues);

        $params['items'] = array_values(array_filter(
            $params['items'],
            // Arrays or SelectItem objects, both with array access.
            static fn(array|\ArrayAccess $item): bool => !in_array((string)($item['value'] ?? ''), $used, true)
        ));
    }

    /**
     * @return list<string> Resources of the client's other grants
     */
    private function resourcesOfClient(int $clientUid, int $exceptUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return array_map('strval', $queryBuilder
            ->select('resource')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('client', $queryBuilder->createNamedParameter($clientUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->neq('uid', $queryBuilder->createNamedParameter($exceptUid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchFirstColumn());
    }
}
