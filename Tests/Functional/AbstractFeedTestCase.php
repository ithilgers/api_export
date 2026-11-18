<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Tests\Functional;

use Ithilgers\ApiExport\Http\ProtectionHeaders;
use Ithilgers\ApiExport\Tests\Functional\Fixtures\Log\MemoryLogWriter;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Log\LogRecord;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Common setup: site "website.local", records from Fixtures/Database
 * (folders, categories, events, news, API clients with grants) and helpers
 * for feed requests.
 *
 * Log records of the extension (warning and above) are collected by
 * MemoryLogWriter.
 */
abstract class AbstractFeedTestCase extends FunctionalTestCase
{
    protected const BASE_URL = 'https://website.local';

    /*
     * Tokens of the clients in Fixtures/Database/Feed.csv, which only contains
     * their SHA-256 hashes.
     */
    protected const TOKEN_ALL = '4c9bb2e28ccb3c7dd827ab94d06f384d58ec841537b6fbb03bd2f5f0e21d2da8';
    protected const TOKEN_LANGUAGES = 'ed92d8f048cf867cfd94064040c15d6e388f7dcd2383a869a5f170920c0d4bc8';
    protected const TOKEN_SUBCATEGORIES = '0d85cb1dcf547e1e2361e5230e57627a25ed8ee9c4fad1939d581e4e9e6cf6ed';
    protected const TOKEN_AND = '8f00ab027ff1677271396bb7be605dc07a8fe8e02410a9b710949c286a75a8e9';
    protected const TOKEN_HIDDEN = 'd7b26f1a1655c1bb68744538a3a49a45f45cbb6e430fae262c77a4c110aa6383';
    protected const TOKEN_EXPIRED = '762b94e7d060551b1632be70dd6db88fdae358601c70e32f1de3de1206bfd59d';
    protected const TOKEN_NOT_YET_VALID = 'e1b109183601b7ad14ffdd36b5b9d8f91df9ef5b0e7005931265705b07c3e047';
    protected const TOKEN_NEWS_ONLY = '3e3a1db7dc02a304858302a84b5822a5573470d8f07fb9d410b3f9602d47cd96';
    protected const TOKEN_SPARE = '6a15c7a638d0b46b7a5eeca95f0f73ba75c49525667b172ec7439793f403d23b';
    protected const TOKEN_DELETED = '8c09fbcf5146b5b471ff217fc3f4a2fe160a5782c7ceb2cb53db0c468557d760';
    protected const TOKEN_NO_PAGES = '5773c6b4768a35120bd19c7584de1b19e3be7eab73fa34c4528643e9c8d5574d';
    protected const TOKEN_FEED_HIDDEN = '51f5d36160685763aae06794c8c2eb65986357eb04e2b49cabbcc73389c5868e';

    /** Grant "events" of the client TOKEN_ALL */
    protected const FEED_ALL_EVENTS = 1;

    protected const DEFAULT_LANGUAGE = [
        'languageId' => 0,
        'title' => 'Deutsch',
        'enabled' => true,
        'base' => '/',
        'locale' => 'de_DE.UTF-8',
        'navigationTitle' => 'Deutsch',
        'flag' => 'de',
    ];

    protected array $testExtensionsToLoad = [
        'derhansen/sf_event_mgt',
        'georgringer/news',
        'ithilgers/api-export',
    ];

    protected array $configurationToUseInTestInstance = [
        'LOG' => [
            'Ithilgers' => [
                'ApiExport' => [
                    'writerConfiguration' => [
                        LogLevel::WARNING => [
                            MemoryLogWriter::class => [],
                        ],
                    ],
                ],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        MemoryLogWriter::reset();
        // Failed attempts are counted per IP address in a file cache that
        // outlives a single test.
        $this->get(CacheManager::class)->getCache('ratelimiter')->flush();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Database/Feed.csv');
        $this->writeSiteConfiguration();
        $this->setUpRootPage();
    }

    /**
     * (Re)writes the site "website" with root page 1.
     *
     * @param list<array<string, mixed>> $languages
     * @param array<string, mixed> $additional
     */
    protected function writeSiteConfiguration(
        string $base = self::BASE_URL . '/',
        array $languages = [self::DEFAULT_LANGUAGE],
        array $additional = [],
        string $identifier = 'website',
        int $rootPageId = 1,
    ): void {
        $this->get(SiteConfiguration::class)->write($identifier, [
            'rootPageId' => $rootPageId,
            'base' => $base,
            'languages' => $languages,
        ] + $additional);
    }

    protected function setUpRootPage(): void
    {
        $this->setUpFrontendRootPage(1, ['EXT:api_export/Tests/Functional/Fixtures/TypoScript/Page.typoscript']);
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function updateFeed(int $feedUid, array $fields): void
    {
        $this->updateRecord('tx_apiexport_domain_model_feed', $feedUid, $fields);
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function updateRecord(string $table, int $uid, array $fields): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable($table)
            ->update($table, $fields, ['uid' => $uid]);
    }

    /**
     * Sets the resource-specific settings (FlexForm) of a grant.
     *
     * @param array<string, string> $settings
     */
    protected function setFeedSettings(int $feedUid, array $settings): void
    {
        $fields = '';
        foreach ($settings as $name => $value) {
            $fields .= sprintf(
                '<field index="%s"><value index="vDEF">%s</value></field>',
                htmlspecialchars($name),
                htmlspecialchars($value)
            );
        }
        $this->updateFeed($feedUid, [
            'settings' => '<?xml version="1.0" encoding="utf-8" standalone="yes" ?><T3FlexForms><data>'
                . '<sheet index="sDEF"><language index="lDEF">' . $fields . '</language></sheet>'
                . '</data></T3FlexForms>',
        ]);
    }

    /**
     * Runs $callback while $table is unavailable (renamed) and restores the
     * table afterwards – so that subsequent tests are not affected (MariaDB
     * resets tables via TRUNCATE only).
     */
    protected function withTableUnavailable(string $table, callable $callback): mixed
    {
        $connection = $this->getConnectionPool()->getConnectionForTable($table);
        $connection->executeStatement(sprintf('ALTER TABLE %s RENAME TO %s_offline', $table, $table));
        try {
            return $callback();
        } finally {
            $connection->executeStatement(sprintf('ALTER TABLE %s_offline RENAME TO %s', $table, $table));
        }
    }

    /**
     * @param array<string, string> $cookies
     * @param string|null $remoteAddress IP address of the caller (default 127.0.0.1)
     */
    protected function feedRequest(
        string $path,
        ?string $token = null,
        string $method = 'GET',
        array $cookies = [],
        ?string $remoteAddress = null,
    ): ResponseInterface {
        $request = (new InternalRequest(self::BASE_URL . $path))->withMethod($method);
        if ($token !== null) {
            $request = $request->withHeader('X-Feed-Token', $token);
        }
        if ($cookies !== []) {
            $request = $request->withCookieParams($cookies);
        }
        if ($remoteAddress !== null) {
            $host = $request->getUri()->getHost();
            $request = $request->withServerParams([
                'SCRIPT_NAME' => '/index.php',
                'HTTP_HOST' => $host,
                'SERVER_NAME' => $host,
                'HTTPS' => 'on',
                'REMOTE_ADDR' => $remoteAddress,
            ]);
        }
        return $this->executeFrontendSubRequest($request);
    }

    protected function decode(ResponseInterface $response): array
    {
        $body = (string)$response->getBody();
        $data = json_decode($body, true);
        self::assertIsArray($data, 'Response is not valid JSON: ' . $body);
        return $data;
    }

    /**
     * @return string[] Titles of the items in output order
     */
    protected function titles(array $feed): array
    {
        return array_column($feed['items'] ?? [], 'title');
    }

    protected function assertProtectionHeaders(ResponseInterface $response): void
    {
        foreach (ProtectionHeaders::HEADERS as $name => $value) {
            self::assertSame($value, $response->getHeaderLine($name), $name);
        }
    }

    /**
     * @return list<LogRecord> Collected records of the extension (warning and above)
     */
    protected function logRecords(?string $level = null): array
    {
        return array_values(array_filter(
            MemoryLogWriter::$records,
            static fn(LogRecord $record): bool => $level === null || $record->getLevel() === $level
        ));
    }
}
