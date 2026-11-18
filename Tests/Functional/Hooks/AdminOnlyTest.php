<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Tests\Functional\Hooks;

use Ithilgers\ApiExport\Tests\Functional\AbstractFeedTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Editor 2 is no admin, but the group may modify all three tables
 * (Fixtures/Database/BackendUser.csv) and everybody may edit the content of
 * the page with the clients.
 */
final class AdminOnlyTest extends AbstractFeedTestCase
{
    private const TABLE = 'tx_apiexport_domain_model_client';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/BackendUser.csv');
        // The mount point (root page) must be readable, page 30 editable.
        $this->updateRecord('pages', 1, ['perms_everybody' => 31]);
        $this->updateRecord('pages', 30, ['perms_everybody' => 31]);
        $backendUser = $this->setUpBackendUser(2);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function editorCannotCreateClientsDespiteTablePermissions(): void
    {
        $dataHandler = $this->createClient();

        self::assertNotSame([], $dataHandler->errorLog);
        self::assertSame(0, $this->countClients());
    }

    #[Test]
    public function editorCannotChangeClients(): void
    {
        $dataHandler = $this->runDataHandler([self::TABLE => [1 => ['title' => 'Changed by editor']]]);

        self::assertNotSame([], $dataHandler->errorLog);
        self::assertSame('All events', $this->getConnectionPool()->getConnectionForTable(self::TABLE)
            ->select(['title'], self::TABLE, ['uid' => 1])->fetchOne());
    }

    #[Test]
    public function withoutAdminOnlyTheEditorCouldCreateClients(): void
    {
        // Control: the permissions of the editor are sufficient otherwise.
        $GLOBALS['TCA'][self::TABLE]['ctrl']['adminOnly'] = false;

        $dataHandler = $this->createClient();

        self::assertSame([], $dataHandler->errorLog);
        self::assertSame(1, $this->countClients());
    }

    private function createClient(): DataHandler
    {
        return $this->runDataHandler([self::TABLE => ['NEW1' => ['pid' => 30, 'title' => 'Created by editor']]]);
    }

    private function runDataHandler(array $data): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();
        return $dataHandler;
    }

    private function countClients(): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        return (int)$queryBuilder->count('uid')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('title', $queryBuilder->createNamedParameter('Created by editor')))
            ->executeQuery()
            ->fetchOne();
    }
}
