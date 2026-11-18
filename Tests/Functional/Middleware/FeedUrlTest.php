<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Tests\Functional\Middleware;

use Ithilgers\ApiExport\Tests\Functional\AbstractFeedTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Position of the middleware and detection of feed URLs.
 */
final class FeedUrlTest extends AbstractFeedTestCase
{
    private const MIDDLEWARE = 'ithilgers/api-export/token-authenticator';

    private const ENGLISH = [
        'languageId' => 1,
        'title' => 'English',
        'enabled' => true,
        'base' => '/en/',
        'locale' => 'en_US.UTF-8',
        'navigationTitle' => 'English',
        'flag' => 'us',
    ];

    #[Test]
    public function middlewareRunsAfterSiteResolutionAndBeforeAuthenticationAndPageResolution(): void
    {
        // The resolved stack lists the middleware that runs first last.
        $order = array_reverse(array_keys($this->get('frontend.middlewares')->getArrayCopy()));
        $position = array_search(self::MIDDLEWARE, $order, true);
        self::assertIsInt($position);

        foreach (['typo3/cms-frontend/site', 'typo3/cms-frontend/maintenance-mode'] as $earlier) {
            self::assertLessThan($position, array_search($earlier, $order, true), $earlier);
        }
        foreach ([
            'typo3/cms-frontend/backend-user-authentication',
            'typo3/cms-frontend/authentication',
            'typo3/cms-frontend/base-redirect-resolver',
            'typo3/cms-frontend/static-route-resolver',
            'typo3/cms-frontend/page-resolver',
        ] as $later) {
            self::assertGreaterThan($position, array_search($later, $order, true), $later);
        }
    }

    #[Test]
    public function feedUrlWithoutLanguagePrefix(): void
    {
        $response = $this->feedRequest('/api/v1/events', self::TOKEN_ALL);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('events', $this->decode($response)['resource']);
    }

    #[Test]
    public function feedUrlWithLanguagePrefix(): void
    {
        $this->writeSiteConfiguration(languages: [self::DEFAULT_LANGUAGE, self::ENGLISH]);

        $response = $this->feedRequest('/en/api/v1/events', self::TOKEN_ALL);

        self::assertSame(200, $response->getStatusCode());
        $feed = $this->decode($response);
        self::assertSame('events', $feed['resource']);
        // The language prefix changes neither the data nor the links.
        self::assertSame(8, $feed['total']);
    }

    #[Test]
    public function feedUrlOfSiteWhoseLanguagesAllHaveAPrefix(): void
    {
        $this->writeSiteConfiguration(languages: [['base' => '/de/'] + self::DEFAULT_LANGUAGE, self::ENGLISH]);

        $withoutPrefix = $this->feedRequest('/api/v1/events', self::TOKEN_ALL);
        $withPrefix = $this->feedRequest('/de/api/v1/events', self::TOKEN_ALL);

        self::assertSame(200, $withoutPrefix->getStatusCode());
        self::assertSame('', $withoutPrefix->getHeaderLine('Location'));
        self::assertSame('events', $this->decode($withoutPrefix)['resource']);
        self::assertSame(200, $withPrefix->getStatusCode());
    }

    #[Test]
    public function feedUrlInSubdirectoryInstallation(): void
    {
        $this->writeSiteConfiguration(base: self::BASE_URL . '/portal/');

        $response = $this->feedRequest('/portal/api/v1/events', self::TOKEN_ALL);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('events', $this->decode($response)['resource']);
    }

    #[Test]
    public function trailingSlashIsIgnored(): void
    {
        $response = $this->feedRequest('/api/v1/events/', self::TOKEN_ALL);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('events', $this->decode($response)['resource']);
    }

    public static function similarPathDataProvider(): array
    {
        return [
            'base path followed by more characters' => ['/api/v1x/events'],
            'base path with another digit' => ['/api/v10/events'],
        ];
    }

    #[Test]
    #[DataProvider('similarPathDataProvider')]
    public function similarPathWithoutPathBoundaryIsNoFeedUrl(string $path): void
    {
        // A feed URL without token would be answered with 401.
        $response = $this->feedRequest($path);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('feed token', (string)$response->getBody());
        self::assertSame('', $response->getHeaderLine('X-Robots-Tag'));
    }

    #[Test]
    public function subPathBelowAResourceIsNotFound(): void
    {
        $response = $this->feedRequest('/api/v1/events/123', self::TOKEN_ALL);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', $this->decode($response)['error']);
    }

    #[Test]
    public function subPathBelowAResourceRequiresTokenFirst(): void
    {
        $response = $this->feedRequest('/api/v1/events/123');

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function formerTypeNumUrlDeliversNoFeedData(): void
    {
        $response = $this->feedRequest('/?type=834751486&resource=events', self::TOKEN_ALL);

        self::assertNotSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('Languages workshop', (string)$response->getBody());
        self::assertStringNotContainsString('"resource"', (string)$response->getBody());
    }

    #[Test]
    public function otherRequestsArePassedThrough(): void
    {
        $response = $this->feedRequest('/');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Home page', (string)$response->getBody());
    }
}
