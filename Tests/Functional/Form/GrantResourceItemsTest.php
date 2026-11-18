<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Tests\Functional\Form;

use Ithilgers\ApiExport\Tests\Functional\AbstractFeedTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The selection of the resource in a grant offers each resource only once per
 * client. Client 1 has grants for "events" (1) and "news" (2), client 8 only
 * for "news" (9).
 */
final class GrantResourceItemsTest extends AbstractFeedTestCase
{
    private const TABLE = 'tx_apiexport_domain_model_feed';
    private const CLIENT_TABLE = 'tx_apiexport_domain_model_client';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/BackendUser.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function newGrantOffersOnlyResourcesWithoutGrant(): void
    {
        self::assertSame(['events'], $this->resourcesOfNewGrant(8));
    }

    #[Test]
    public function deactivatedGrantStillOccupiesItsResource(): void
    {
        $this->updateFeed(9, ['hidden' => 1]);

        self::assertSame(['events'], $this->resourcesOfNewGrant(8));
    }

    #[Test]
    public function newClientOffersAllResources(): void
    {
        self::assertSame(['events', 'news'], $this->resourcesOfNewGrant('NEW1234'));
    }

    #[Test]
    public function savedGrantKeepsItsOwnResource(): void
    {
        self::assertSame(['news'], $this->resources(['command' => 'edit', 'vanillaUid' => 2]));
    }

    /**
     * @return list<string>
     */
    private function resourcesOfNewGrant(int|string $clientUid): array
    {
        return $this->resources([
            'command' => 'new',
            'vanillaUid' => 30,
            'isInlineChild' => true,
            'inlineParentUid' => $clientUid,
            'inlineParentTableName' => self::CLIENT_TABLE,
            'inlineParentFieldName' => 'feeds',
            'inlineParentConfig' => $GLOBALS['TCA'][self::CLIENT_TABLE]['columns']['feeds']['config'],
        ]);
    }

    /**
     * Values offered in the field "resource", as compiled for the backend form.
     *
     * @param array<string, mixed> $input
     * @return list<string>
     */
    private function resources(array $input): array
    {
        $request = (new ServerRequest(self::BASE_URL . '/typo3/record/edit'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $formData = GeneralUtility::makeInstance(FormDataCompiler::class)->compile(
            ['request' => $request, 'tableName' => self::TABLE] + $input,
            GeneralUtility::makeInstance(TcaDatabaseRecord::class)
        );
        return array_column($formData['processedTca']['columns']['resource']['config']['items'], 'value');
    }
}
