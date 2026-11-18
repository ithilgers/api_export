<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Feed;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\VisibilityAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Domain\Access\RecordAccessVoter;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Exception\Page\RootLineException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

/**
 * Answers whether a page is reachable for anonymous visitors, independent of
 * any frontend or backend login, preview setting or workspace of the request.
 *
 * Works with its own Context: no frontend user (groups 0 and -1), no backend
 * user, default visibility, live workspace and default language.
 *
 * - Folders (isFolderAccessible): the folder itself and every ancestor with
 *   "Extend to subpages" must allow anonymous visitors (fe_group). "hidden"
 *   and the publishing dates do not count, just like for the storage folders
 *   of a list plugin.
 * - Pages (isPageVisible): additionally "hidden", start and stop, again for
 *   the page itself and for every ancestor with "Extend to subpages".
 *
 * A page that does not exist, is deleted or only exists in a workspace is
 * never reachable. Rootlines are kept for the lifetime of the instance, which
 * is one feed request (the service is not shared).
 */
final class AnonymousPageAccess
{
    private readonly Context $context;

    /** @var array<int, array<int, array<string, mixed>>|null> */
    private array $rootlines = [];

    public function __construct(
        private readonly RecordAccessVoter $accessVoter,
    ) {
        $this->context = new Context([
            'date' => new DateTimeAspect(new \DateTimeImmutable('@' . (int)($GLOBALS['EXEC_TIME'] ?? time()))),
            'visibility' => new VisibilityAspect(),
            'frontend.user' => new UserAspect(null, [0, -1]),
            'backend.user' => new UserAspect(),
            'workspace' => new WorkspaceAspect(0),
            'language' => new LanguageAspect(),
        ]);
    }

    /**
     * The anonymous Context, e.g. for the router of a site.
     */
    public function getContext(): Context
    {
        return $this->context;
    }

    /**
     * Page repository that sees pages like an anonymous visitor.
     */
    public function createPageRepository(): PageRepository
    {
        return GeneralUtility::makeInstance(PageRepository::class, $this->context);
    }

    /**
     * Rootline of the page (the page itself first, the site root last), or
     * null if the page does not exist for anonymous visitors.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function getRootline(int $pageId): ?array
    {
        if ($pageId <= 0) {
            return null;
        }
        if (!array_key_exists($pageId, $this->rootlines)) {
            try {
                $this->rootlines[$pageId] = GeneralUtility::makeInstance(
                    RootlineUtility::class,
                    $pageId,
                    '',
                    $this->context
                )->get();
            } catch (RootLineException) {
                $this->rootlines[$pageId] = null;
            }
        }
        return $this->rootlines[$pageId];
    }

    public function isFolderAccessible(int $pageId): bool
    {
        return $this->isGrantedInRootline(
            $pageId,
            fn(array $page): bool => $this->accessVoter->groupAccessGranted('pages', $page, $this->context)
        );
    }

    public function isPageVisible(int $pageId): bool
    {
        return $this->isGrantedInRootline(
            $pageId,
            fn(array $page): bool => $this->accessVoter->accessGranted('pages', $page, $this->context)
        );
    }

    /**
     * @param \Closure(array<string, mixed>): bool $isGranted
     */
    private function isGrantedInRootline(int $pageId, \Closure $isGranted): bool
    {
        $rootline = $this->getRootline($pageId);
        if ($rootline === null) {
            return false;
        }
        foreach ($rootline as $page) {
            $appliesToPage = (int)($page['uid'] ?? 0) === $pageId || (bool)($page['extendToSubpages'] ?? false);
            if ($appliesToPage && !$isGranted($page)) {
                return false;
            }
        }
        return true;
    }
}
