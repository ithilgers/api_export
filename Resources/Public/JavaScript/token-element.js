/**
 * Form element "apiExportToken" of a saved API client: shows and enables the
 * prepared new token only while "Generate new token" is checked. A disabled
 * field is not submitted, so saving without the checkbox keeps the previous
 * token.
 */
import DocumentService from '@typo3/core/document-service.js';
import FormEngineValidation from '@typo3/backend/form-engine-validation.js';

export default class TokenElement {
  constructor(checkboxId) {
    DocumentService.ready().then(() => {
      const checkbox = document.getElementById(checkboxId);
      const container = checkbox ? document.getElementById(checkbox.dataset.apiExportTokenTarget) : null;
      const input = container ? container.querySelector('input') : null;
      if (input === null) {
        return;
      }
      checkbox.addEventListener('change', () => {
        container.hidden = !checkbox.checked;
        input.disabled = !checkbox.checked;
        // Ask before leaving the form, as for any other change.
        FormEngineValidation.markFieldAsChanged(checkbox);
      });
    });
  }
}
