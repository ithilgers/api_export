<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Feed;

use Ithilgers\ApiExport\Event\ModifyFeedItemEvent;
use Ithilgers\ApiExport\Http\FeedRequest;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\EndTimeRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\StartTimeRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Service\FlexFormService;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Generates the JSON feed for an authorized request (events, news, …).
 *
 * From the client's grant it builds the demand for the matching provider:
 * storage folders, category filter, resource-specific settings, detail links
 * and pagination. Every item passes through ModifyFeedItemEvent, the result
 * is a uniform JSON envelope including pagination details.
 *
 * Storage folders that anonymous visitors cannot access are dropped together
 * with their subfolders; subfolders are resolved as for an anonymous visitor.
 * Without storage folders, the feed returns an empty list.
 *
 * Exceptions are not caught here: the middleware answers them with a JSON
 * error (500), or delivers the response of an intentional abort.
 */
final class FeedRenderer
{
    private const DETAILMAP_TABLE = 'tx_apiexport_domain_model_detailmap';
    private const CATEGORY_TABLE = 'sys_category';
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __construct(
        private readonly FeedProviderRegistry $registry,
        private readonly ConnectionPool $connectionPool,
        private readonly FlexFormService $flexFormService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly SiteFinder $siteFinder,
    ) {}

    public function render(FeedRequest $feedRequest, ServerRequestInterface $request): ResponseInterface
    {
        $resource = $feedRequest->resource;
        $feed = $feedRequest->feed;
        $pagination = $feedRequest->pagination->withMaxItems((int)($feed['max_items'] ?? 0));
        $provider = $this->registry->get($resource)
            ?? throw new \RuntimeException('No provider for resource "' . $resource . '".', 1729584002);

        // One instance per request: it caches rootlines.
        $pageAccess = GeneralUtility::makeInstance(AnonymousPageAccess::class);

        $result = $provider->findItems(new FeedDemand(
            $resource,
            $this->resolveStoragePages($feed, $pageAccess),
            $this->buildCategoryFilter($feed),
            $this->flexFormService->convertFlexFormContentToArray((string)($feed['settings'] ?? '')),
            new DetailLinkBuilder(
                $this->siteFinder,
                $pageAccess,
                $this->loadDetailMap((int)($feed['uid'] ?? 0)),
                (int)($feed['detail_pid'] ?? 0)
            ),
            $pagination->getOffset(),
            $pagination->getFetchLimit(),
        ));

        $items = [];
        foreach ($result->items as $feedItem) {
            $event = $this->eventDispatcher->dispatch(
                new ModifyFeedItemEvent($resource, $feedItem->data, $feedItem->record, $feedRequest->client, $request)
            );
            $items[] = $event->getItem();
        }

        $total = $pagination->capTotal($result->total);
        $json = json_encode([
            'version' => '1.0',
            'resource' => $resource,
            'total' => $total,
            'page' => $pagination->page,
            'pages' => $pagination->countPages($total),
            'limit' => $pagination->limit,
            'count' => count($items),
            'items' => $items,
        ], self::JSON_FLAGS);

        $response = new Response();
        $response->getBody()->write($json);
        return $response;
    }

    /**
     * Storage folders of the grant that anonymous visitors may access,
     * optionally including their subfolders.
     *
     * @return int[]
     */
    private function resolveStoragePages(array $feed, AnonymousPageAccess $pageAccess): array
    {
        $pageIds = array_values(array_filter(
            array_unique(GeneralUtility::intExplode(',', (string)($feed['storage_pages'] ?? ''), true)),
            static fn(int $uid): bool => $uid > 0 && $pageAccess->isFolderAccessible($uid)
        ));

        $depth = (int)($feed['recursive'] ?? 0);
        if ($pageIds !== [] && $depth > 0) {
            $pageIds = $pageAccess->createPageRepository()->getPageIdsRecursive($pageIds, $depth);
        }
        return array_values($pageIds);
    }

    /**
     * Category filter of the grant. With "Include subcategories", each
     * selected category also counts as satisfied via its active
     * subcategories (see CategoryFilter).
     */
    private function buildCategoryFilter(array $feed): CategoryFilter
    {
        $filter = CategoryFilter::fromList(
            (string)($feed['categories'] ?? ''),
            (string)($feed['category_conjunction'] ?? '')
        );
        if ($filter->isEmpty() || !(bool)($feed['category_include_subcategories'] ?? false)) {
            return $filter;
        }
        return $filter->withSubcategories($this->findSubcategories(...));
    }

    /**
     * @return int[] all active subcategories (recursive) of the category
     */
    private function findSubcategories(int $categoryUid): array
    {
        $all = [$categoryUid => true];
        $parents = [$categoryUid];
        // Categories already seen are not visited again -> even faulty
        // cycles in the category tree terminate.
        while ($parents !== []) {
            $qb = $this->connectionPool->getQueryBuilderForTable(self::CATEGORY_TABLE);
            $qb->getRestrictions()
                ->removeAll()
                ->add(new DeletedRestriction())
                ->add(new HiddenRestriction())
                ->add(new StartTimeRestriction())
                ->add(new EndTimeRestriction())
                ->add(new WorkspaceRestriction(0));
            $children = $qb->select('uid')
                ->from(self::CATEGORY_TABLE)
                ->where(
                    $qb->expr()->in('parent', $qb->createNamedParameter($parents, Connection::PARAM_INT_ARRAY))
                )
                ->executeQuery()
                ->fetchFirstColumn();

            $parents = [];
            foreach ($children as $uid) {
                $uid = (int)$uid;
                if (!isset($all[$uid])) {
                    $all[$uid] = true;
                    $parents[] = $uid;
                }
            }
        }
        unset($all[$categoryUid]);
        return array_keys($all);
    }

    /**
     * Detail pages per folder of a grant.
     *
     * @return array<int, int> storage_pid => detail_pid (valid pairs only)
     */
    private function loadDetailMap(int $feedUid): array
    {
        if ($feedUid <= 0) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(self::DETAILMAP_TABLE);
        $qb->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new HiddenRestriction());

        $rows = $qb->select('storage_pid', 'detail_pid')
            ->from(self::DETAILMAP_TABLE)
            ->where(
                $qb->expr()->eq('feed', $qb->createNamedParameter($feedUid, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            $storage = (int)$row['storage_pid'];
            $detail = (int)$row['detail_pid'];
            if ($storage > 0 && $detail > 0) {
                $map[$storage] = $detail;
            }
        }
        return $map;
    }
}
