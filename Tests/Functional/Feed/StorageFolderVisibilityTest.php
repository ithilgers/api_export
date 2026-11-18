<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Tests\Functional\Feed;

use Ithilgers\ApiExport\Tests\Functional\AbstractFeedTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Session\UserSessionManager;

/**
 * Storage folders of a grant as for anonymous visitors.
 *
 * Grant 1 (client TOKEN_ALL): folder 10, 8 visible events.
 * Grant 3 (client TOKEN_LANGUAGES): folder 10 plus one level (subfolder 11),
 * category 1.
 */
final class StorageFolderVisibilityTest extends AbstractFeedTestCase
{
    #[Test]
    public function folderBelowAProtectedPageDeliversNothing(): void
    {
        $this->updateFeed(self::FEED_ALL_EVENTS, ['storage_pages' => '11']);
        self::assertSame(1, $this->total(self::TOKEN_ALL));

        $this->updateRecord('pages', 10, ['fe_group' => '1', 'extendToSubpages' => 1]);

        self::assertSame(0, $this->total(self::TOKEN_ALL));
    }

    #[Test]
    public function protectedFolderEnteredDirectlyDeliversNothing(): void
    {
        $this->updateRecord('pages', 10, ['fe_group' => '1']);

        self::assertSame(0, $this->total(self::TOKEN_ALL));
    }

    #[Test]
    public function folderHiddenAtLoginIsDelivered(): void
    {
        $this->updateRecord('pages', 10, ['fe_group' => '-1']);

        self::assertSame(8, $this->total(self::TOKEN_ALL));
    }

    #[Test]
    public function hiddenFolderEnteredDirectlyIsDelivered(): void
    {
        $this->updateRecord('pages', 10, ['hidden' => 1]);

        self::assertSame(8, $this->total(self::TOKEN_ALL));
    }

    #[Test]
    public function protectedAndHiddenSubfoldersAreSkipped(): void
    {
        self::assertContains('Languages in subfolder', $this->titles($this->feed(self::TOKEN_LANGUAGES)));

        $this->updateRecord('pages', 11, ['fe_group' => '1']);
        self::assertNotContains('Languages in subfolder', $this->titles($this->feed(self::TOKEN_LANGUAGES)));

        $this->updateRecord('pages', 11, ['fe_group' => '', 'hidden' => 1]);
        self::assertNotContains('Languages in subfolder', $this->titles($this->feed(self::TOKEN_LANGUAGES)));
    }

    #[Test]
    public function frontendLoginDoesNotChangeTheResult(): void
    {
        // The member's group may see subfolder 11 and the event restricted to group 1.
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/FrontendUser.csv');
        $this->updateRecord('pages', 11, ['fe_group' => '1']);
        $this->updateFeed(3, ['categories' => '']);
        $anonymous = $this->feed(self::TOKEN_LANGUAGES);

        $loggedIn = $this->feed(self::TOKEN_LANGUAGES, ['fe_typo_user' => $this->sessionCookie('FE', 1)]);

        self::assertSame($this->titles($anonymous), $this->titles($loggedIn));
        self::assertNotContains('Languages in subfolder', $this->titles($loggedIn));
        self::assertNotContains('Members only', $this->titles($loggedIn));
    }

    #[Test]
    public function backendLoginWithPreviewDoesNotChangeTheResult(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/BackendUser.csv');
        $this->updateRecord('pages', 11, ['hidden' => 1]);
        $this->updateFeed(3, ['categories' => '']);
        $anonymous = $this->feed(self::TOKEN_LANGUAGES);

        // An admin, with the preview of hidden pages and records switched on.
        $loggedIn = $this->feed(
            self::TOKEN_LANGUAGES,
            ['be_typo_user' => $this->sessionCookie('BE', 1)],
            '&ADMCMD_simTime=' . 2147000000
        );

        self::assertSame($this->titles($anonymous), $this->titles($loggedIn));
        self::assertNotContains('Languages in subfolder', $this->titles($loggedIn));
        self::assertNotContains('Hidden languages event', $this->titles($loggedIn));
    }

    private function total(string $token): int
    {
        return $this->feed($token)['total'];
    }

    /**
     * @param array<string, string> $cookies
     */
    private function feed(string $token, array $cookies = [], string $query = ''): array
    {
        return $this->decode($this->feedRequest('/api/v1/events?limit=100' . $query, $token, 'GET', $cookies));
    }

    /**
     * Session of a logged-in user, as a browser would send it.
     */
    private function sessionCookie(string $loginType, int $userId): string
    {
        $sessionManager = UserSessionManager::create($loginType);
        return $sessionManager
            ->elevateToFixatedUserSession($sessionManager->createAnonymousSession(), $userId)
            ->getJwt();
    }
}
