<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Form\Element;

use Ithilgers\ApiExport\Security\ClientToken;
use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Form element of the virtual field "token" of an API client (renderType
 * "apiExportToken").
 *
 * Generates a new token on every rendering; nothing can be typed in:
 *
 * - New client: the token in a read-only field with a copy button and the
 *   note that it is shown only now. Saving stores its hash and hint.
 * - Saved client: only "token stored, ends with …" and the checkbox
 *   "Generate new token". The prepared field is shown and enabled only when
 *   the checkbox is checked; a disabled field is not submitted, so saving
 *   without the checkbox keeps the previous token.
 *
 * A value in databaseRow (e.g. from defVals in the URL) is ignored, so that
 * nobody can slip in a known token via a link. Reloading the form
 * (onChange: reload) saves the record, the displayed token stays valid.
 */
final class TokenElement extends AbstractFormElement
{
    private const LL = 'LLL:EXT:api_export/Resources/Private/Language/locallang_db.xlf:client.token.';

    public function render(): array
    {
        $resultArray = $this->initializeResultArray();
        // @deprecated since v12, can be removed with v13 when all elements handle the label on their own
        $resultArray['labelHasBeenHandled'] = true;
        $resultArray['javaScriptModules'][] = JavaScriptModuleInstruction::create('@typo3/backend/copy-to-clipboard.js');

        $fieldInformationResult = $this->renderFieldInformation();
        $resultArray = $this->mergeChildReturnIntoExistingResult($resultArray, $fieldInformationResult, false);

        $token = ClientToken::generate();
        $fieldId = StringUtility::getUniqueId('formengine-api-export-token-');
        $isNew = ($this->data['command'] ?? '') === 'new';

        $html = [];
        if ($isNew) {
            $html[] = $this->renderLabel($fieldId);
            $html[] = '<div class="formengine-field-item t3js-formengine-field-item">';
            $html[] = $fieldInformationResult['html'];
            $html[] = $this->renderTokenField($fieldId, $token, false);
            $html[] = '<p class="form-text">' . $this->label('newHint') . '</p>';
            $html[] = '</div>';
        } else {
            $checkboxId = $fieldId . '-regenerate';
            $containerId = $fieldId . '-new';
            $hint = (string)($this->data['databaseRow']['token_hint'] ?? '');
            $resultArray['javaScriptModules'][] = JavaScriptModuleInstruction::create('@ithilgers/api-export/token-element.js')
                ->instance($checkboxId);

            $html[] = $this->renderLabel($checkboxId);
            $html[] = '<div class="formengine-field-item t3js-formengine-field-item">';
            $html[] = $fieldInformationResult['html'];
            $html[] = '<p>' . ($hint !== ''
                ? sprintf($this->label('stored'), '<code>…' . htmlspecialchars($hint) . '</code>')
                : $this->label('none')) . '</p>';
            $html[] = '<div class="form-check form-switch">';
            $html[] = '<input type="checkbox" class="form-check-input" id="' . htmlspecialchars($checkboxId) . '"'
                . ' data-api-export-token-target="' . htmlspecialchars($containerId) . '">';
            $html[] = '<label class="form-check-label" for="' . htmlspecialchars($checkboxId) . '">'
                . $this->label('regenerate') . '</label>';
            $html[] = '</div>';
            $html[] = '<div id="' . htmlspecialchars($containerId) . '" hidden>';
            $html[] = $this->renderTokenField($fieldId, $token, true);
            $html[] = '<p class="form-text">' . $this->label('regenerateHint') . '</p>';
            $html[] = '</div>';
            $html[] = '</div>';
        }

        $resultArray['html'] = implode(LF, $html);
        return $resultArray;
    }

    /**
     * Read-only field with the token and a copy button.
     */
    private function renderTokenField(string $fieldId, string $token, bool $disabled): string
    {
        $attributes = [
            'id' => $fieldId,
            'name' => (string)$this->data['parameterArray']['itemFormElName'],
            'type' => 'text',
            'value' => $token,
            'class' => 'form-control font-monospace',
            'readonly' => 'readonly',
            'autocomplete' => 'off',
            'spellcheck' => 'false',
        ];
        if ($disabled) {
            $attributes['disabled'] = 'disabled';
        }
        $width = $this->formMaxWidth($this->maxInputWidth);

        return '<div class="form-control-wrap" style="max-width: ' . $width . 'px">'
            . '<div class="input-group">'
            . '<input ' . GeneralUtility::implodeAttributes($attributes, true) . ' />'
            . '<typo3-copy-to-clipboard class="btn btn-default" title="' . $this->label('copy') . '"'
            . ' text="' . htmlspecialchars($token) . '">'
            . $this->iconFactory->getIcon('actions-clipboard', Icon::SIZE_SMALL)
            . '</typo3-copy-to-clipboard>'
            . '</div>'
            . '</div>';
    }

    /**
     * Translated, HTML-escaped label.
     */
    private function label(string $key): string
    {
        return htmlspecialchars($this->getLanguageService()->sL(self::LL . $key));
    }
}
