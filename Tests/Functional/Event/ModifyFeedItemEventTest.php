<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Tests\Functional\Event;

use Ithilgers\ApiExport\Tests\Functional\AbstractFeedTestCase;
use Ithilgers\ApiExportTest\EventListener\AddRecordFieldsListener;
use PHPUnit\Framework\Attributes\Test;

/**
 * Loads the fixture extension api_export_test, whose listener adds "pid",
 * "client", "clientFields" and request details to events, removes the teaser
 * from news and aborts with its own response for a specific client.
 */
final class ModifyFeedItemEventTest extends AbstractFeedTestCase
{
    protected array $testExtensionsToLoad = [
        'derhansen/sf_event_mgt',
        'georgringer/news',
        'ithilgers/api-export',
        __DIR__ . '/../Fixtures/Extensions/api_export_test',
    ];

    #[Test]
    public function listenerCanAddFieldsFromRecordAndClient(): void
    {
        $feed = $this->decode($this->feedRequest('/api/v1/events', self::TOKEN_ALL));

        self::assertNotSame([], $feed['items']);
        foreach ($feed['items'] as $item) {
            self::assertSame(10, $item['pid']);
            self::assertSame('All events', $item['client']);
        }
    }

    #[Test]
    public function listenerCanReadTheRequest(): void
    {
        $feed = $this->decode($this->feedRequest('/api/v1/events?page=1&limit=2', self::TOKEN_ALL));

        self::assertSame('/api/v1/events', $feed['items'][0]['requestPath']);
        self::assertSame('1', $feed['items'][0]['requestPage']);
    }

    #[Test]
    public function clientPassedToListenersHasNoTokenFields(): void
    {
        $feed = $this->decode($this->feedRequest('/api/v1/events', self::TOKEN_ALL));

        self::assertSame(['uid', 'title'], $feed['items'][0]['clientFields']);
    }

    #[Test]
    public function listenerCanRemoveFields(): void
    {
        $feed = $this->decode($this->feedRequest('/api/v1/news', self::TOKEN_ALL));

        self::assertNotSame([], $feed['items']);
        foreach ($feed['items'] as $item) {
            self::assertArrayNotHasKey('teaser', $item);
        }
    }

    #[Test]
    public function listenerCanAbortWithItsOwnResponse(): void
    {
        $this->updateRecord('tx_apiexport_domain_model_client', 1, ['title' => AddRecordFieldsListener::ABORTING_CLIENT]);

        $response = $this->feedRequest('/api/v1/events', self::TOKEN_ALL);

        self::assertSame(410, $response->getStatusCode());
        self::assertSame('Gone', $this->decode($response)['error']);
        $this->assertProtectionHeaders($response);
    }
}
