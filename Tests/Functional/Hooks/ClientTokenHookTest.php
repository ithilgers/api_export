<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Tests\Functional\Hooks;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ithilgers\ApiExport\Tests\Functional\AbstractFeedTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Saving tokens via the DataHandler (as backend form and CLI command do).
 */
final class ClientTokenHookTest extends AbstractFeedTestCase
{
    private const TABLE = 'tx_apiexport_domain_model_client';
    private const FEED_TABLE = 'tx_apiexport_domain_model_feed';
    private const NEW_TOKEN = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';
    private const OTHER_NEW_TOKEN = '60303ae22b998861bce3b28f33eec1be758a213c86c93c076dbe9f558c11c752';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/BackendUser.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function tokenHashIsUniqueInTheDatabase(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);

        // Client 1 already has this hash; the deleted client 10 counts as well.
        $this->getConnectionPool()->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid' => 30,
            'title' => 'Duplicate',
            'token_hash' => hash('sha256', self::TOKEN_ALL),
        ]);
    }

    #[Test]
    public function validTokenIsStoredAsHashAndHint(): void
    {
        $dataHandler = $this->runDataHandler([self::TABLE => [1 => ['token' => self::NEW_TOKEN]]]);

        self::assertSame([], $dataHandler->errorLog);
        $client = $this->client(1);
        self::assertSame(hash('sha256', self::NEW_TOKEN), $client['token_hash']);
        self::assertSame('0a08', $client['token_hint']);
        self::assertSame(200, $this->feedRequest('/api/v1/events', self::NEW_TOKEN)->getStatusCode());
        self::assertSame(401, $this->feedRequest('/api/v1/events', self::TOKEN_ALL)->getStatusCode());
    }

    #[Test]
    public function newClientWithTokenIsStoredAsHashAndHint(): void
    {
        $dataHandler = $this->runDataHandler([self::TABLE => ['NEW1' => [
            'pid' => 30,
            'title' => 'New client',
            'token' => self::NEW_TOKEN,
        ]]]);

        $client = $this->client((int)$dataHandler->substNEWwithIDs['NEW1']);
        self::assertSame(hash('sha256', self::NEW_TOKEN), $client['token_hash']);
        self::assertSame('0a08', $client['token_hint']);
        self::assertSame(0, (int)$client['hidden']);
    }

    public static function malformedTokenDataProvider(): array
    {
        return [
            'upper case' => [strtoupper(self::NEW_TOKEN)],
            'too short' => [substr(self::NEW_TOKEN, 0, 63)],
            'with a space' => [' ' . self::NEW_TOKEN],
            'entered by hand' => ['my-secret-token-for-the-partner-website-2026'],
        ];
    }

    #[Test]
    #[DataProvider('malformedTokenDataProvider')]
    public function malformedTokenIsRejectedAndTheOldTokenStaysValid(string $token): void
    {
        $dataHandler = $this->runDataHandler([self::TABLE => [1 => ['title' => 'Renamed', 'token' => $token]]]);

        self::assertNotSame([], $dataHandler->errorLog);
        $client = $this->client(1);
        self::assertSame('Renamed', $client['title']);
        self::assertSame(hash('sha256', self::TOKEN_ALL), $client['token_hash']);
        self::assertSame(200, $this->feedRequest('/api/v1/events', self::TOKEN_ALL)->getStatusCode());
    }

    #[Test]
    public function newClientWithMalformedTokenGetsAnUnknownHash(): void
    {
        $token = strtoupper(self::NEW_TOKEN);
        $dataHandler = $this->runDataHandler([self::TABLE => ['NEW1' => ['pid' => 30, 'title' => 'New', 'token' => $token]]]);

        self::assertNotSame([], $dataHandler->errorLog);
        $client = $this->client((int)$dataHandler->substNEWwithIDs['NEW1']);
        $this->assertIsUnknownHash($client['token_hash']);
        self::assertSame('', $client['token_hint']);
    }

    #[Test]
    public function newClientWithoutTokenGetsAnUnknownHash(): void
    {
        $dataHandler = $this->runDataHandler([self::TABLE => ['NEW1' => ['pid' => 30, 'title' => 'New client']]]);

        self::assertSame([], $dataHandler->errorLog);
        $client = $this->client((int)$dataHandler->substNEWwithIDs['NEW1']);
        $this->assertIsUnknownHash($client['token_hash']);
        self::assertSame('', $client['token_hint']);
    }

    #[Test]
    public function incomingTokenHashIsDiscarded(): void
    {
        $foreignHash = hash('sha256', self::NEW_TOKEN);

        $dataHandler = $this->runDataHandler([self::TABLE => [
            1 => ['token_hash' => $foreignHash, 'token_hint' => '0a08'],
            'NEW1' => ['pid' => 30, 'title' => 'Imported', 'token_hash' => $foreignHash, 'token_hint' => '0a08'],
        ]]);

        $existing = $this->client(1);
        self::assertSame(hash('sha256', self::TOKEN_ALL), $existing['token_hash']);
        self::assertSame('2da8', $existing['token_hint']);
        $new = $this->client((int)$dataHandler->substNEWwithIDs['NEW1']);
        $this->assertIsUnknownHash($new['token_hash']);
        self::assertNotSame($foreignHash, $new['token_hash']);
        self::assertSame('', $new['token_hint']);
        self::assertSame(1, (int)$new['hidden']);
    }

    #[Test]
    public function copiedClientIsDeactivatedWithAnUnknownHashAndTheSameGrants(): void
    {
        $dataHandler = $this->runDataHandler([], [self::TABLE => [1 => ['copy' => 30]]]);
        $copyUid = (int)$dataHandler->copyMappingArray_merged[self::TABLE][1];

        $copy = $this->client($copyUid);
        self::assertSame(1, (int)$copy['hidden']);
        $this->assertIsUnknownHash($copy['token_hash']);
        self::assertSame('', $copy['token_hint']);
        self::assertSame($this->grantsOf(1), $this->grantsOf($copyUid));
        // The original's token keeps working and only authenticates the original.
        self::assertSame(hash('sha256', self::TOKEN_ALL), $this->client(1)['token_hash']);
        self::assertSame(200, $this->feedRequest('/api/v1/events', self::TOKEN_ALL)->getStatusCode());
    }

    #[Test]
    public function copyingAPageWithTwoClientsDeactivatesBothCopies(): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')
            ->insert('pages', ['uid' => 31, 'pid' => 1, 'title' => 'More clients', 'doktype' => 254, 'slug' => '/more-clients']);
        $clients = $this->getConnectionPool()->getConnectionForTable(self::TABLE);
        $clients->insert(self::TABLE, ['uid' => 20, 'pid' => 31, 'title' => 'First', 'token_hash' => hash('sha256', self::NEW_TOKEN)]);
        $clients->insert(self::TABLE, ['uid' => 21, 'pid' => 31, 'title' => 'Second', 'token_hash' => hash('sha256', self::OTHER_NEW_TOKEN)]);

        $dataHandler = $this->runDataHandler([], ['pages' => [31 => ['copy' => 1]]]);

        $copies = $dataHandler->copyMappingArray_merged[self::TABLE] ?? [];
        self::assertCount(2, $copies);
        $hashes = [];
        foreach ($copies as $copyUid) {
            $copy = $this->client((int)$copyUid);
            self::assertSame(1, (int)$copy['hidden'], $copy['title']);
            $this->assertIsUnknownHash($copy['token_hash']);
            $hashes[] = $copy['token_hash'];
        }
        self::assertNotSame($hashes[0], $hashes[1]);
        self::assertNotContains(hash('sha256', self::NEW_TOKEN), $hashes);
        self::assertNotContains(hash('sha256', self::OTHER_NEW_TOKEN), $hashes);
    }

    #[Test]
    public function restoredClientKeepsItsToken(): void
    {
        $this->runDataHandler([], [self::TABLE => [1 => ['delete' => 1]]]);
        self::assertSame(401, $this->feedRequest('/api/v1/events', self::TOKEN_ALL)->getStatusCode());

        // The core does not restore the inline grants along with the client.
        $dataHandler = $this->runDataHandler([], [
            self::TABLE => [1 => ['undelete' => 1]],
            self::FEED_TABLE => [self::FEED_ALL_EVENTS => ['undelete' => 1]],
        ]);

        self::assertSame([], $dataHandler->errorLog);
        self::assertSame(hash('sha256', self::TOKEN_ALL), $this->client(1)['token_hash']);
        self::assertSame(200, $this->feedRequest('/api/v1/events', self::TOKEN_ALL)->getStatusCode());
    }

    #[Test]
    public function tokenIsNeitherInHistoryNorLogNorSession(): void
    {
        $malformed = 'my-secret-token-for-the-partner-website-2026';
        $this->runDataHandler([self::TABLE => [1 => ['title' => 'Renamed', 'token' => self::NEW_TOKEN]]]);
        $this->runDataHandler([self::TABLE => ['NEW1' => ['pid' => 30, 'title' => 'New', 'token' => self::OTHER_NEW_TOKEN]]]);
        // Errors are also put into the session as flash messages.
        $this->runDataHandler([self::TABLE => [2 => ['token' => $malformed]]])->printLogErrorMessages();

        self::assertNotSame(0, $this->countRows('sys_history'));
        self::assertNotSame(0, $this->countRows('sys_log'));
        self::assertNotSame(0, $this->countRows('be_sessions'));
        foreach ([self::TABLE, 'sys_history', 'sys_log', 'be_sessions'] as $table) {
            $content = serialize(
                $this->unrestrictedQueryBuilder($table)->select('*')->from($table)->executeQuery()->fetchAllAssociative()
            );
            foreach ([self::NEW_TOKEN, self::OTHER_NEW_TOKEN, $malformed] as $token) {
                self::assertStringNotContainsString($token, $content, $table);
            }
        }
    }

    private function runDataHandler(array $data, array $commands = []): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, $commands);
        $dataHandler->process_datamap();
        $dataHandler->process_cmdmap();
        return $dataHandler;
    }

    /**
     * Including deactivated clients (copies are deactivated).
     */
    private function client(int $uid): array
    {
        $queryBuilder = $this->unrestrictedQueryBuilder(self::TABLE);
        $row = $queryBuilder->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $uid))
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row, 'Client ' . $uid . ' not found');
        return $row;
    }

    /**
     * @return list<array{resource: string, storage_pages: string}>
     */
    private function grantsOf(int $clientUid): array
    {
        $queryBuilder = $this->unrestrictedQueryBuilder(self::FEED_TABLE);
        return $queryBuilder->select('resource', 'storage_pages')
            ->from(self::FEED_TABLE)
            ->where(
                $queryBuilder->expr()->eq('client', $clientUid),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function countRows(string $table): int
    {
        return (int)$this->unrestrictedQueryBuilder($table)->count('*')->from($table)->executeQuery()->fetchOne();
    }

    private function unrestrictedQueryBuilder(string $table): QueryBuilder
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        return $queryBuilder;
    }

    /**
     * A well-formed hash that belongs to no other client.
     */
    private function assertIsUnknownHash(mixed $hash): void
    {
        self::assertIsString($hash);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        $queryBuilder = $this->unrestrictedQueryBuilder(self::TABLE);
        self::assertSame(1, (int)$queryBuilder->count('uid')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('token_hash', $queryBuilder->createNamedParameter($hash)))
            ->executeQuery()
            ->fetchOne());
        foreach ([self::TOKEN_ALL, self::NEW_TOKEN, self::OTHER_NEW_TOKEN] as $token) {
            self::assertNotSame(hash('sha256', $token), $hash);
        }
    }
}
