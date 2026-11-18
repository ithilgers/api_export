<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Tests\Functional\Form;

use Ithilgers\ApiExport\Tests\Functional\AbstractFeedTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Backend\Form\NodeFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The form element of the virtual field "token", rendered with the data the
 * backend form compiles for a new or a saved client.
 */
final class TokenElementTest extends AbstractFeedTestCase
{
    private const TABLE = 'tx_apiexport_domain_model_client';
    private const KNOWN_TOKEN = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/BackendUser.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function newClientShowsAGeneratedTokenWithCopyButton(): void
    {
        $html = $this->render(['command' => 'new', 'vanillaUid' => 30])['html'];

        $token = $this->tokenIn($html);
        self::assertMatchesRegularExpression('/<typo3-copy-to-clipboard[^>]+text="' . $token . '"/', $html);
        self::assertMatchesRegularExpression('/<input[^>]+name="data\[' . self::TABLE . '\]\[NEW[^\]]+\]\[token\]"[^>]+readonly/', $html);
        self::assertStringNotContainsString('disabled', $html);
        self::assertStringNotContainsString('type="checkbox"', $html);
    }

    #[Test]
    public function everyRenderingGeneratesAnotherToken(): void
    {
        $first = $this->tokenIn($this->render(['command' => 'new', 'vanillaUid' => 30])['html']);
        $second = $this->tokenIn($this->render(['command' => 'new', 'vanillaUid' => 30])['html']);

        self::assertNotSame($first, $second);
    }

    #[Test]
    public function savedClientShowsTheHintAndADisabledTokenField(): void
    {
        $result = $this->render(['command' => 'edit', 'vanillaUid' => 1]);
        $html = $result['html'];

        self::assertStringContainsString('<code>…2da8</code>', $html);
        self::assertStringNotContainsString(self::TOKEN_ALL, $html);
        self::assertMatchesRegularExpression('/<input type="checkbox"[^>]+data-api-export-token-target=/', $html);
        self::assertMatchesRegularExpression('/<div id="[^"]+-new" hidden>/', $html);
        self::assertMatchesRegularExpression('/<input[^>]+name="data\[' . self::TABLE . '\]\[1\]\[token\]"[^>]+disabled/', $html);
        $this->tokenIn($html);
        self::assertContains('@ithilgers/api-export/token-element.js', array_map(
            static fn(JavaScriptModuleInstruction $instruction): string => $instruction->getName(),
            $result['javaScriptModules']
        ));
    }

    #[Test]
    public function virtualTokenFieldIsNeverSelectedFromTheDatabase(): void
    {
        // The List module selects only these fields; "token" has no column.
        self::assertNotContains('token', BackendUtility::getAllowedFieldsForTable(self::TABLE));
        self::assertContains('title', BackendUtility::getAllowedFieldsForTable(self::TABLE));
    }

    #[Test]
    public function clientWithoutUsableTokenSaysSo(): void
    {
        $this->updateRecord(self::TABLE, 1, ['token_hint' => '']);

        $html = $this->render(['command' => 'edit', 'vanillaUid' => 1])['html'];

        self::assertStringContainsString('No usable token is stored', $html);
    }

    #[Test]
    public function tokenFromTheUrlIsIgnored(): void
    {
        // defVals in the URL end up in databaseRow of a new record.
        $html = $this->render([
            'command' => 'new',
            'vanillaUid' => 30,
            'defaultValues' => [self::TABLE => ['token' => self::KNOWN_TOKEN]],
        ])['html'];

        self::assertStringNotContainsString(self::KNOWN_TOKEN, $html);
    }

    #[Test]
    public function reloadingTheFormSavesTheDisplayedToken(): void
    {
        $token = $this->tokenIn($this->render(['command' => 'new', 'vanillaUid' => 30])['html']);

        // "onChange: reload" (e.g. of a grant) submits and saves the whole form.
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([self::TABLE => ['NEW1' => ['pid' => 30, 'title' => 'New client', 'token' => $token]]], []);
        $dataHandler->process_datamap();
        $uid = (int)$dataHandler->substNEWwithIDs['NEW1'];

        $html = $this->render(['command' => 'edit', 'vanillaUid' => $uid])['html'];

        self::assertStringContainsString('<code>…' . substr($token, -4) . '</code>', $html);
        self::assertStringNotContainsString($token, $html);
        self::assertSame(
            hash('sha256', $token),
            $this->getConnectionPool()->getConnectionForTable(self::TABLE)
                ->select(['token_hash'], self::TABLE, ['uid' => $uid])->fetchOne()
        );
    }

    /**
     * Renders the field "token" the way the backend form does.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function render(array $input): array
    {
        $request = (new ServerRequest(self::BASE_URL . '/typo3/record/edit'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $formData = GeneralUtility::makeInstance(FormDataCompiler::class)->compile(
            ['request' => $request, 'tableName' => self::TABLE] + $input,
            GeneralUtility::makeInstance(TcaDatabaseRecord::class)
        );
        $fieldConfiguration = $formData['processedTca']['columns']['token'];
        $formData['fieldName'] = 'token';
        $formData['renderType'] = $fieldConfiguration['config']['renderType'];
        $formData['parameterArray'] = [
            'fieldConf' => $fieldConfiguration,
            'itemFormElName' => 'data[' . self::TABLE . '][' . $formData['databaseRow']['uid'] . '][token]',
            'itemFormElValue' => $formData['databaseRow']['token'] ?? '',
            'fieldChangeFunc' => [],
        ];
        return GeneralUtility::makeInstance(NodeFactory::class)->create($formData)->render();
    }

    private function tokenIn(string $html): string
    {
        self::assertSame(1, preg_match('/<input[^>]+name="[^"]+\[token\]"[^>]+value="([0-9a-f]{64})"/', $html, $matches), $html);
        return $matches[1];
    }
}
