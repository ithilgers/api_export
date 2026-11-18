<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Tests\Functional\Feed;

use Ithilgers\ApiExport\Tests\Functional\AbstractFeedTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Detail links via the router of the target page's site, and links of news
 * of the types "internal page" and "external URL".
 *
 * Grant 3 (client TOKEN_LANGUAGES) maps folder 10 to detail page 40
 * ("/event-detail"); its items lie in folder 10 and subfolder 11.
 * Grant 2 (client TOKEN_ALL) delivers news with detail page 60.
 */
final class DetailLinkTest extends AbstractFeedTestCase
{
    private const EVENT_DETAIL_ENHANCER = [
        'routeEnhancers' => [
            'EventDetail' => [
                'type' => 'Extbase',
                'limitToPages' => [40],
                'extension' => 'SfEventMgt',
                'plugin' => 'Pieventdetail',
                'routes' => [
                    ['routePath' => '/{event}', '_controller' => 'Event::detail', '_arguments' => ['event' => 'event']],
                ],
                'defaultController' => 'Event::detail',
                'aspects' => [
                    'event' => ['type' => 'StaticRangeMapper', 'start' => '1', 'end' => '100'],
                ],
            ],
        ],
    ];

    #[Test]
    public function mappingOfAParentFolderIsInherited(): void
    {
        $items = $this->itemsByTitle('/api/v1/events', self::TOKEN_LANGUAGES);

        // "Languages in subfolder" lies in folder 11, the mapping is on folder 10.
        self::assertStringStartsWith(self::BASE_URL . '/event-detail?', $items['Languages in subfolder']['link']);
        self::assertStringContainsString('[event]=8', urldecode($items['Languages in subfolder']['link']));
        self::assertStringContainsString('cHash=', $items['Languages in subfolder']['link']);
    }

    #[Test]
    public function routeEnhancerOfTheSiteIsApplied(): void
    {
        $this->writeSiteConfiguration(additional: self::EVENT_DETAIL_ENHANCER);

        $items = $this->itemsByTitle('/api/v1/events', self::TOKEN_LANGUAGES);

        self::assertSame(self::BASE_URL . '/event-detail/1', $items['Languages workshop']['link']);
        self::assertSame(self::BASE_URL . '/event-detail/8', $items['Languages in subfolder']['link']);
    }

    #[Test]
    public function detailPageInAnotherSiteStartsWithItsBase(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/SecondSite.csv');
        $this->writeSiteConfiguration(
            base: 'https://other.local/',
            identifier: 'other',
            rootPageId: 100
        );
        $this->updateRecord('tx_apiexport_domain_model_detailmap', 1, ['detail_pid' => 101]);

        $items = $this->itemsByTitle('/api/v1/events', self::TOKEN_LANGUAGES);

        self::assertStringStartsWith('https://other.local/event-detail?', $items['Languages workshop']['link']);
    }

    public static function unreachableDetailPageDataProvider(): array
    {
        return [
            'hidden' => [['hidden' => 1]],
            'deleted' => [['deleted' => 1]],
            'access-restricted' => [['fe_group' => '1']],
            'below a protected page' => [['pid' => 20], ['uid' => 20, 'fe_group' => '1', 'extendToSubpages' => 1]],
        ];
    }

    /**
     * @param array<string, mixed> $detailPage
     * @param array<string, mixed> $otherPage
     */
    #[Test]
    #[DataProvider('unreachableDetailPageDataProvider')]
    public function unreachableDetailPageResultsInAnEmptyLink(array $detailPage, array $otherPage = []): void
    {
        $this->updateRecord('pages', 40, $detailPage);
        if ($otherPage !== []) {
            $this->updateRecord('pages', (int)$otherPage['uid'], array_diff_key($otherPage, ['uid' => 0]));
        }

        $items = $this->itemsByTitle('/api/v1/events', self::TOKEN_LANGUAGES);

        self::assertNotSame([], $items);
        foreach ($items as $title => $item) {
            self::assertSame('', $item['link'], $title);
        }
    }

    public static function internalPageDataProvider(): array
    {
        return [
            'link syntax' => ['t3://page?uid=40'],
            'plain uid of older records' => ['40'],
        ];
    }

    #[Test]
    #[DataProvider('internalPageDataProvider')]
    public function newsOfTypeInternalPageLinksToThatPage(string $internalUrl): void
    {
        $this->updateRecord('tx_news_domain_model_news', 1, ['type' => '1', 'internalurl' => $internalUrl]);

        $items = $this->itemsByTitle('/api/v1/news', self::TOKEN_ALL);

        self::assertSame(self::BASE_URL . '/event-detail', $items['Languages news']['link']);
    }

    #[Test]
    public function newsOfTypeInternalPageKeepsFragmentAndParameters(): void
    {
        $this->updateRecord('tx_news_domain_model_news', 1, ['type' => '1', 'internalurl' => 't3://page?uid=40&tab=2#c12']);

        $link = $this->itemsByTitle('/api/v1/news', self::TOKEN_ALL)['Languages news']['link'];

        self::assertStringStartsWith(self::BASE_URL . '/event-detail?tab=2', $link);
        self::assertStringEndsWith('#c12', $link);
    }

    #[Test]
    public function newsOfTypeInternalPageOnAProtectedPageHasAnEmptyLink(): void
    {
        $this->updateRecord('pages', 40, ['fe_group' => '1']);
        $this->updateRecord('tx_news_domain_model_news', 1, ['type' => '1', 'internalurl' => 't3://page?uid=40']);

        $items = $this->itemsByTitle('/api/v1/news', self::TOKEN_ALL);

        self::assertSame('', $items['Languages news']['link']);
    }

    #[Test]
    public function newsOfTypeInternalPageWithoutAPageHasAnEmptyLink(): void
    {
        $this->updateRecord('tx_news_domain_model_news', 1, ['type' => '1', 'internalurl' => 't3://file?uid=999']);

        $items = $this->itemsByTitle('/api/v1/news', self::TOKEN_ALL);

        self::assertSame('', $items['Languages news']['link']);
    }

    #[Test]
    public function newsOfTypeExternalUrlTakesTheUrlAsItIs(): void
    {
        $this->updateRecord('tx_news_domain_model_news', 1, [
            'type' => '2',
            'externalurl' => ' https://partner.example/artikel?id=7&lang=de ',
        ]);

        $items = $this->itemsByTitle('/api/v1/news', self::TOKEN_ALL);

        self::assertSame('https://partner.example/artikel?id=7&lang=de', $items['Languages news']['link']);
    }

    #[Test]
    public function regularNewsKeepTheDetailLink(): void
    {
        $this->updateRecord('tx_news_domain_model_news', 1, ['type' => '0']);

        $items = $this->itemsByTitle('/api/v1/news', self::TOKEN_ALL);

        self::assertStringStartsWith(self::BASE_URL . '/news-detail?', $items['Languages news']['link']);
        self::assertStringContainsString('[news]=1', urldecode($items['Languages news']['link']));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function itemsByTitle(string $path, string $token): array
    {
        return array_column($this->decode($this->feedRequest($path, $token))['items'], null, 'title');
    }
}
