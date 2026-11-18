<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Feed\Provider;

use Ithilgers\ApiExport\Feed\FeedDemand;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\LinkHandling\LinkService;

/**
 * Delivers news from georgringer/news as feed items.
 *
 * Deliberately accesses the table directly via Doctrine so that the extension
 * has no hard dependency on georgringer/news (optional data type). If
 * georgringer/news is not installed, the provider returns an empty list.
 *
 * "archiveRestriction" corresponds to the setting of the same name in the
 * news list plugin ('' = all, active, archived).
 *
 * Like in the list plugin, news of the type "internal page" link to that page
 * and news of the type "external URL" to that URL instead of the detail page.
 */
final class NewsFeedProvider extends AbstractTableFeedProvider
{
    private const ORDER_FIELDS = ['datetime', 'title', 'crdate', 'tstamp', 'sorting'];
    private const TYPE_INTERNAL_PAGE = '1';
    private const TYPE_EXTERNAL_URL = '2';

    public function __construct(
        ConnectionPool $connectionPool,
        private readonly LinkService $linkService,
    ) {
        parent::__construct($connectionPool);
    }

    public function getResource(): string
    {
        return 'news';
    }

    protected function getTableName(): string
    {
        return 'tx_news_domain_model_news';
    }

    protected function getCategoryField(): ?string
    {
        return 'categories';
    }

    protected function getOrdering(array $settings): array
    {
        $field = (string)($settings['orderField'] ?? 'datetime');
        $direction = strtoupper((string)($settings['orderDirection'] ?? 'desc')) === 'ASC' ? 'ASC' : 'DESC';
        return [in_array($field, self::ORDER_FIELDS, true) ? $field : 'datetime', $direction];
    }

    protected function addConstraints(QueryBuilder $queryBuilder, FeedDemand $demand): void
    {
        $archiveRestriction = (string)($demand->settings['archiveRestriction'] ?? 'active');
        if (!in_array($archiveRestriction, ['active', 'archived'], true)) {
            // '' = all news
            return;
        }

        $now = $queryBuilder->createNamedParameter($GLOBALS['EXEC_TIME'] ?? time(), Connection::PARAM_INT);
        $zero = $queryBuilder->createNamedParameter(0, Connection::PARAM_INT);
        $archive = self::ALIAS . '.archive';

        $queryBuilder->andWhere(match ($archiveRestriction) {
            'active' => $queryBuilder->expr()->or(
                $queryBuilder->expr()->eq($archive, $zero),
                $queryBuilder->expr()->gt($archive, $now),
            ),
            'archived' => $queryBuilder->expr()->and(
                $queryBuilder->expr()->gt($archive, $zero),
                $queryBuilder->expr()->lte($archive, $now),
            ),
        });
    }

    protected function buildItem(array $record, FeedDemand $demand): array
    {
        return [
            'uid' => (int)$record['uid'],
            'title' => (string)$record['title'],
            'teaser' => (string)$record['teaser'],
            'date' => $this->isoDate((int)$record['datetime']),
            'link' => $this->buildLink($record, $demand),
        ];
    }

    private function buildLink(array $record, FeedDemand $demand): string
    {
        return match ((string)($record['type'] ?? '')) {
            self::TYPE_INTERNAL_PAGE => $this->buildInternalLink(trim((string)($record['internalurl'] ?? '')), $demand),
            self::TYPE_EXTERNAL_URL => trim((string)($record['externalurl'] ?? '')),
            default => $demand->detailLinks->build((int)$record['pid'], [
                'tx_news_pi1' => [
                    'controller' => 'News',
                    'action' => 'detail',
                    'news' => (int)$record['uid'],
                ],
            ]),
        };
    }

    /**
     * Link of a news of the type "internal page": a page (t3://page?uid=…,
     * or a plain uid in older records) or a URL; anything else (file,
     * record, …) results in an empty link.
     */
    private function buildInternalLink(string $target, FeedDemand $demand): string
    {
        if ($target === '') {
            return '';
        }
        try {
            $link = $this->linkService->resolve($target);
        } catch (\Exception) {
            // Unknown syntax, or e.g. a link to a file that no longer exists.
            return '';
        }

        if (($link['type'] ?? '') === LinkService::TYPE_URL) {
            return (string)($link['url'] ?? '');
        }
        if (($link['type'] ?? '') !== LinkService::TYPE_PAGE || !is_int($link['pageuid'] ?? null)) {
            return '';
        }
        parse_str((string)($link['parameters'] ?? ''), $arguments);
        return $demand->detailLinks->buildPageLink($link['pageuid'], $arguments, (string)($link['fragment'] ?? ''));
    }
}
