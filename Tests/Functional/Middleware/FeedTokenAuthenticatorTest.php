<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Tests\Functional\Middleware;

use Ithilgers\ApiExport\Tests\Functional\AbstractFeedTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class FeedTokenAuthenticatorTest extends AbstractFeedTestCase
{
    #[Test]
    public function validTokenReturnsFeed(): void
    {
        $response = $this->feedRequest('/api/v1/events', self::TOKEN_ALL);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('events', $this->decode($response)['resource']);
    }

    public static function rejectedTokenDataProvider(): array
    {
        return [
            'no token' => [null],
            'unknown token' => [str_repeat('0123456789abcdef', 4)],
            'token in upper case' => [strtoupper(self::TOKEN_ALL)],
            'token one character too short' => [substr(self::TOKEN_ALL, 0, 63)],
            'token with trailing garbage' => [self::TOKEN_ALL . 'a'],
            'token with a space in the middle' => [substr(self::TOKEN_ALL, 0, 32) . ' ' . substr(self::TOKEN_ALL, 32)],
            'other format' => ['all-events-0123456789abcdef0123456789abcdef'],
            'stored hash instead of the token' => [hash('sha256', self::TOKEN_ALL)],
            'deactivated client' => [self::TOKEN_HIDDEN],
            'deleted client' => [self::TOKEN_DELETED],
            'expired client' => [self::TOKEN_EXPIRED],
            'client not yet valid' => [self::TOKEN_NOT_YET_VALID],
        ];
    }

    #[Test]
    #[DataProvider('rejectedTokenDataProvider')]
    public function invalidTokenIsRejected(?string $token): void
    {
        $response = $this->feedRequest('/api/v1/events', $token);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Unauthorized', $this->decode($response)['error']);
    }

    #[Test]
    public function malformedTokenIsRejectedWithoutDatabaseQuery(): void
    {
        $response = $this->withTableUnavailable(
            'tx_apiexport_domain_model_client',
            fn() => $this->feedRequest('/api/v1/events', strtoupper(self::TOKEN_ALL))
        );

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function unknownResourceWithoutTokenDoesNotRevealResources(): void
    {
        $response = $this->feedRequest('/api/v1/unknown');

        self::assertSame(401, $response->getStatusCode());
        self::assertStringNotContainsString('news', (string)$response->getBody());
    }

    #[Test]
    public function unknownResourceWithTokenReturnsNotFound(): void
    {
        $response = $this->feedRequest('/api/v1/unknown', self::TOKEN_ALL);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['error' => 'Not Found', 'message' => 'Unknown feed resource.'], $this->decode($response));
    }

    #[Test]
    public function resourceWithoutFeedForClientIsForbidden(): void
    {
        $response = $this->feedRequest('/api/v1/events', self::TOKEN_NEWS_ONLY);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Forbidden', $this->decode($response)['error']);
    }

    #[Test]
    public function deactivatedFeedIsForbidden(): void
    {
        $response = $this->feedRequest('/api/v1/events', self::TOKEN_FEED_HIDDEN);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function firstGrantBySortingDecidesForDuplicateResources(): void
    {
        $this->insertSecondEventsGrant(sorting: 0);

        $feed = $this->decode($this->feedRequest('/api/v1/events', self::TOKEN_ALL));

        self::assertSame(['Event in foreign folder'], array_column($feed['items'], 'title'));
    }

    #[Test]
    public function deactivatedFirstGrantIsForbiddenEvenIfAnotherGrantIsActive(): void
    {
        $this->updateFeed(self::FEED_ALL_EVENTS, ['hidden' => 1]);
        $this->insertSecondEventsGrant(sorting: 10);

        $response = $this->feedRequest('/api/v1/events', self::TOKEN_ALL);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Forbidden', $this->decode($response)['error']);
    }

    /**
     * Active grant "events" of client TOKEN_ALL for folder 20, besides grant 1 (sorting 1).
     */
    private function insertSecondEventsGrant(int $sorting): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable('tx_apiexport_domain_model_feed')
            ->insert('tx_apiexport_domain_model_feed', [
                'uid' => 100,
                'pid' => 30,
                'client' => 1,
                'resource' => 'events',
                'storage_pages' => '20',
                'sorting' => $sorting,
            ]);
    }

    public static function invalidPaginationDataProvider(): array
    {
        return [
            'page zero' => ['page=0'],
            'negative page' => ['page=-1'],
            'page not a number' => ['page=abc'],
            'page as array' => ['page[]=1'],
            'limit zero' => ['limit=0'],
            'limit above maximum' => ['limit=501'],
            'limit with decimals' => ['limit=2.5'],
        ];
    }

    #[Test]
    #[DataProvider('invalidPaginationDataProvider')]
    public function invalidPaginationParametersAreRejected(string $query): void
    {
        $response = $this->feedRequest('/api/v1/events?' . $query, self::TOKEN_ALL);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('Bad Request', $this->decode($response)['error']);
    }

    #[Test]
    public function invalidPaginationIsOnlyReportedToAuthenticatedClients(): void
    {
        $response = $this->feedRequest('/api/v1/events?limit=0');

        self::assertSame(401, $response->getStatusCode());
    }

    public static function disallowedMethodDataProvider(): array
    {
        return [
            'POST' => ['POST'],
            'PUT' => ['PUT'],
            'DELETE' => ['DELETE'],
        ];
    }

    #[Test]
    #[DataProvider('disallowedMethodDataProvider')]
    public function writeMethodsAreNotAllowed(string $method): void
    {
        $response = $this->feedRequest('/api/v1/events', self::TOKEN_ALL, $method);

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
    }

    #[Test]
    public function successfulRequestUpdatesLastUsed(): void
    {
        $this->feedRequest('/api/v1/events', self::TOKEN_ALL);

        $lastUsed = (int)$this->getConnectionPool()
            ->getConnectionForTable('tx_apiexport_domain_model_client')
            ->select(['last_used'], 'tx_apiexport_domain_model_client', ['uid' => 1])
            ->fetchOne();
        self::assertGreaterThan(0, $lastUsed);
    }

    #[Test]
    public function databaseErrorDuringAuthorizationIsReportedAsJson(): void
    {
        $response = $this->withTableUnavailable(
            'tx_apiexport_domain_model_feed',
            fn() => $this->feedRequest('/api/v1/events', self::TOKEN_ALL)
        );

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Internal Server Error', $this->decode($response)['error']);
        self::assertStringNotContainsString('tx_apiexport', (string)$response->getBody());
    }
}
