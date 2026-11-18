<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Tests\Functional\Command;

use Ithilgers\ApiExport\Command\ClientTokenCommand;
use Ithilgers\ApiExport\Tests\Functional\AbstractFeedTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

final class ClientTokenCommandTest extends AbstractFeedTestCase
{
    private const TABLE = 'tx_apiexport_domain_model_client';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/BackendUser.csv');
        // Like the CLI application: backend user and its language are set up.
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function newTokenIsPrintedOnceAndReplacesTheOldOne(): void
    {
        $tester = $this->execute('1');

        self::assertSame(0, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', trim($tester->getDisplay()));
        self::assertSame('', $tester->getErrorOutput());
        $token = trim($tester->getDisplay());
        self::assertSame(hash('sha256', $token), $this->hashOf(1));
        self::assertSame(200, $this->feedRequest('/api/v1/events', $token)->getStatusCode());
        self::assertSame(401, $this->feedRequest('/api/v1/events', self::TOKEN_ALL)->getStatusCode());
    }

    public static function invalidUidDataProvider(): array
    {
        return [
            'unknown' => ['999'],
            'deleted' => ['10'],
            'not a number' => ['all'],
            'zero' => ['0'],
        ];
    }

    #[Test]
    #[DataProvider('invalidUidDataProvider')]
    public function unknownOrDeletedClientFailsWithoutChanges(string $uid): void
    {
        $before = $this->allHashes();

        $tester = $this->execute($uid);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('no API client', $tester->getErrorOutput());
        self::assertSame($before, $this->allHashes());
    }

    #[Test]
    public function deactivatedClientGetsTheTokenAndANoteOnStderr(): void
    {
        $tester = $this->execute('5');

        self::assertSame(0, $tester->getStatusCode());
        $token = trim($tester->getDisplay());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        self::assertStringContainsString('deactivated', $tester->getErrorOutput());
        self::assertStringNotContainsString($token, $tester->getErrorOutput());
        self::assertSame(hash('sha256', $token), $this->hashOf(5));
        // Still deactivated.
        self::assertSame(401, $this->feedRequest('/api/v1/events', $token)->getStatusCode());
    }

    #[Test]
    public function expiredClientGetsANoteOnStderr(): void
    {
        $tester = $this->execute('6');

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('outside its access validity', $tester->getErrorOutput());
    }

    private function execute(string $uid): CommandTester
    {
        $tester = new CommandTester($this->get(ClientTokenCommand::class));
        $tester->execute(['uid' => $uid], ['capture_stderr_separately' => true]);
        return $tester;
    }

    private function hashOf(int $uid): ?string
    {
        return $this->allHashes()[$uid] ?? null;
    }

    /**
     * @return array<int, string|null> uid => token_hash of all clients
     */
    private function allHashes(): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder->select('uid', 'token_hash')->from(self::TABLE)->orderBy('uid')->executeQuery()->fetchAllAssociative();
        return array_column($rows, 'token_hash', 'uid');
    }
}
