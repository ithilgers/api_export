<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Tests\Functional\Middleware;

use Ithilgers\ApiExport\Tests\Functional\AbstractFeedTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Log\LogRecord;

/**
 * Limit and log of requests without a valid token (default: 10 failed
 * attempts per IP address in 15 minutes).
 */
final class FailedAttemptsTest extends AbstractFeedTestCase
{
    private const IP = '192.0.2.10';
    private const OTHER_IP = '198.51.100.20';
    private const WRONG_TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setFailedAttemptsLimit(10);
    }

    #[Test]
    public function furtherAttemptsAfterTenFailedOnesAreTooManyRequests(): void
    {
        $this->failedAttempts(10, 401);

        $response = $this->feedRequest('/api/v1/events', self::WRONG_TOKEN, remoteAddress: self::IP);
        $withoutToken = $this->feedRequest('/api/v1/events', remoteAddress: self::IP);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('Too Many Requests', $this->decode($response)['error']);
        self::assertMatchesRegularExpression('/^[1-9][0-9]*$/', $response->getHeaderLine('Retry-After'));
        // At most the time window of 15 minutes, plus rounding to whole seconds.
        self::assertLessThanOrEqual(901, (int)$response->getHeaderLine('Retry-After'));
        $this->assertProtectionHeaders($response);
        self::assertSame(429, $withoutToken->getStatusCode());
    }

    #[Test]
    public function validTokenIsServedDespiteTheLimit(): void
    {
        $this->failedAttempts(11);

        $response = $this->feedRequest('/api/v1/events', self::TOKEN_ALL, remoteAddress: self::IP);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function requestsWithValidTokenAreNotCounted(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->feedRequest('/api/v1/events?limit=1', self::TOKEN_ALL, remoteAddress: self::IP);
        }

        $this->failedAttempts(10, 401);
    }

    #[Test]
    public function otherIpAddressIsNotAffected(): void
    {
        $this->failedAttempts(11);

        $response = $this->feedRequest('/api/v1/events', self::WRONG_TOKEN, remoteAddress: self::OTHER_IP);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function limitZeroAlwaysAnswersUnauthorized(): void
    {
        $this->setFailedAttemptsLimit(0);

        $this->failedAttempts(15, 401);
    }

    #[Test]
    public function fiftyFailedAttemptsAreLoggedAtMostTwice(): void
    {
        $this->failedAttempts(50);

        $warnings = $this->logRecords(LogLevel::WARNING);
        self::assertCount(2, $warnings);
        self::assertSame(self::IP, $warnings[0]->getData()['remoteAddress']);
        self::assertSame('/api/v1/events', $warnings[0]->getData()['path']);
        self::assertStringContainsString('too many requests', $warnings[1]->getMessage());
        $this->assertTokenIsNotLogged($warnings);
    }

    #[Test]
    public function withoutLimitEveryFailedAttemptIsLogged(): void
    {
        $this->setFailedAttemptsLimit(0);

        $this->failedAttempts(50);

        $warnings = $this->logRecords(LogLevel::WARNING);
        self::assertCount(50, $warnings);
        $this->assertTokenIsNotLogged($warnings);
    }

    /**
     * Requests with a wrong token from self::IP.
     */
    private function failedAttempts(int $count, ?int $expectedStatus = null): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $response = $this->feedRequest('/api/v1/events', self::WRONG_TOKEN, remoteAddress: self::IP);
            if ($expectedStatus !== null) {
                self::assertSame($expectedStatus, $response->getStatusCode(), 'Attempt ' . $i);
            }
        }
    }

    /**
     * @param list<LogRecord> $records
     */
    private function assertTokenIsNotLogged(array $records): void
    {
        foreach ($records as $record) {
            $content = $record->getMessage() . json_encode($record->getData());
            self::assertStringNotContainsString(self::WRONG_TOKEN, $content);
            self::assertStringNotContainsString(substr(self::WRONG_TOKEN, 0, 16), $content);
        }
    }

    private function setFailedAttemptsLimit(int $limit): void
    {
        $extensionConfiguration = $this->get(ExtensionConfiguration::class);
        $extensionConfiguration->set(
            'api_export',
            ['failedAttemptsLimit' => (string)$limit] + $extensionConfiguration->get('api_export')
        );
    }
}
