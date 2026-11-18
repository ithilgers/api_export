<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Tests\Functional\Middleware;

use Ithilgers\ApiExport\Tests\Functional\AbstractFeedTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every response to a feed URL is JSON with the protective headers,
 * regardless of the root page of the site.
 */
final class FeedResponseTest extends AbstractFeedTestCase
{
    public static function rootPageDataProvider(): array
    {
        return [
            'shortcut to a subpage' => [['doktype' => 4, 'shortcut' => 40, 'shortcut_mode' => 0]],
            'hidden' => [['hidden' => 1]],
            'restricted to a frontend group' => [['fe_group' => '1']],
        ];
    }

    /**
     * @param array<string, mixed> $rootPage
     */
    #[Test]
    #[DataProvider('rootPageDataProvider')]
    public function feedDoesNotDependOnTheRootPage(array $rootPage): void
    {
        $this->updateRecord('pages', 1, $rootPage);

        $response = $this->feedRequest('/api/v1/events', self::TOKEN_ALL);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Location'));
        self::assertSame(8, $this->decode($response)['total']);
    }

    #[Test]
    public function headRequestIsAnsweredLikeGetWithoutBody(): void
    {
        $get = $this->feedRequest('/api/v1/events', self::TOKEN_ALL);
        $head = $this->feedRequest('/api/v1/events', self::TOKEN_ALL, 'HEAD');

        self::assertSame(200, $head->getStatusCode());
        $this->assertProtectionHeaders($head);
        self::assertSame('', (string)$head->getBody());
        self::assertNotSame('', (string)$get->getBody());
    }

    public static function statusDataProvider(): array
    {
        return [
            '200' => [200, '/api/v1/events', self::TOKEN_ALL, 'GET'],
            '400' => [400, '/api/v1/events?limit=0', self::TOKEN_ALL, 'GET'],
            '401' => [401, '/api/v1/events', null, 'GET'],
            '403' => [403, '/api/v1/events', self::TOKEN_NEWS_ONLY, 'GET'],
            '404' => [404, '/api/v1/unknown', self::TOKEN_ALL, 'GET'],
            '405' => [405, '/api/v1/events', self::TOKEN_ALL, 'POST'],
        ];
    }

    #[Test]
    #[DataProvider('statusDataProvider')]
    public function everyResponseCarriesTheProtectionHeaders(int $status, string $path, ?string $token, string $method): void
    {
        $response = $this->feedRequest($path, $token, $method);

        self::assertSame($status, $response->getStatusCode());
        $this->assertProtectionHeaders($response);
        $this->decode($response);
    }

    #[Test]
    public function serverErrorCarriesTheProtectionHeaders(): void
    {
        $response = $this->withTableUnavailable(
            'tx_apiexport_domain_model_feed',
            fn() => $this->feedRequest('/api/v1/events', self::TOKEN_ALL)
        );

        self::assertSame(500, $response->getStatusCode());
        $this->assertProtectionHeaders($response);
        self::assertSame('Internal Server Error', $this->decode($response)['error']);
    }
}
