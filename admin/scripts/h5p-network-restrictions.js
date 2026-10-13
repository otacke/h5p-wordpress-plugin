/* global H5PNetworkRestrictions H5PPluginConfirmationDialog */
/**
 * Network Restrictions page: save changed checkboxes, run bulk actions on sites matching search, reload for counts.
 */
(() => {
  const STORAGE_KEY = 'h5p-network-restrictions-notice';

  const mount = () => {
    const container = document.querySelector('.h5p-network-restrictions');
    if (!container) {
      return;
    }

    // typeof guard, not optional chaining: the global is absent if print_settings emitted nothing.
    const settings = typeof H5PNetworkRestrictions !== 'undefined' ? H5PNetworkRestrictions : {};
    const l10n = settings.l10n || {};
    const region = container.querySelector('.h5p-network-restrictions-notices');
    const checkboxes = [...container.querySelectorAll('[data-h5p-restriction]')];
    const toggleAll = container.querySelector('[data-h5p-restrictions-toggle-all]');
    let busy = false;

    /**
     * Append a dismissible admin notice.
     *
     * @param {string} type 'success' or 'error'
     * @param {string} message
     */
    const show = (type, message) => {
      window.H5PNetworkCommon.showNotice(region, type, message, l10n.dismiss);
    };

    /**
     * Get the checkboxes whose state differs from the one the page was rendered with.
     *
     * @return {HTMLInputElement[]}
     */
    const getChanged = () => checkboxes.filter(checkbox => checkbox.checked !== checkbox.defaultChecked);

    const updateToggleAll = () => {
      if (!toggleAll) {
        return;
      }
      const checked = checkboxes.filter(checkbox => checkbox.checked).length;
      toggleAll.checked = checked > 0 && checked === checkboxes.length;
      toggleAll.indeterminate = checked > 0 && checked < checkboxes.length;
    };

    /**
     * Post to a network restrictions endpoint.
     *
     * @param {string} action The AJAX action.
     * @param {URLSearchParams} body Parameters besides action and nonce.
     *
     * @return {Promise<Object>} The data of a successful response.
     */
    const post = (action, body) => window.H5PNetworkCommon.postAjax(settings, action, body, l10n.requestFailed);

    /**
     * Run a request, then reload with a success notice, or show its error.
     *
     * @param {function(): Promise} request
     */
    const run = async (request) => {
      if (busy) {
        return;
      }
      busy = true;
      container.setAttribute('aria-busy', 'true');

      try {
        await request();
      }
      catch (error) {
        busy = false;
        container.removeAttribute('aria-busy');
        show('error', error.message);
        return;
      }

      // Nothing is unsaved anymore, so leaving must not warn.
      checkboxes.forEach(checkbox => {
        checkbox.defaultChecked = checkbox.checked;
      });
      window.H5PNetworkCommon.storeNoticeAndReload(STORAGE_KEY, {type: 'success', message: l10n.saved});
    };

    const save = () => {
      const changed = getChanged();
      if (!changed.length) {
        show('success', l10n.nothingChanged);
        return;
      }

      run(() => {
        const body = new URLSearchParams();
        changed.forEach(checkbox => {
          body.append(checkbox.checked ? 'restrict[]' : 'allow[]', checkbox.dataset.h5pRestriction);
        });
        return post('h5p_network_restrictions_save', body);
      });
    };

    /**
     * Ask the user to confirm a bulk action, then run it.
     *
     * @param {HTMLButtonElement} button
     */
    const apply = (button) => {
      H5PPluginConfirmationDialog.ask({
        l10n: {
          message: button.dataset.confirmMessage,
          cancel: l10n.cancel,
          confirm: button.dataset.confirmLabel
        }
      }, {
        onConfirm: () => run(() => post('h5p_network_restrictions_apply', new URLSearchParams({
          library: button.dataset.library,
          s: button.dataset.search,
          restricted: button.dataset.h5pRestrictionsApply
        })))
      });
    };

    container.addEventListener('click', (event) => {
      const button = event.target.closest('button');
      if (!button) {
        return;
      }

      if (button.hasAttribute('data-h5p-restrictions-save')) {
        save();
      }
      else if (button.hasAttribute('data-h5p-restrictions-apply')) {
        apply(button);
      }
    });

    if (toggleAll) {
      toggleAll.addEventListener('change', () => {
        checkboxes.forEach(checkbox => {
          checkbox.checked = toggleAll.checked;
        });
      });
      checkboxes.forEach(checkbox => checkbox.addEventListener('change', updateToggleAll));
      updateToggleAll();
    }

    window.addEventListener('beforeunload', (event) => {
      if (getChanged().length) {
        event.preventDefault();
        // Older browsers show the dialog only with a returnValue.
        event.returnValue = l10n.unsavedChanges;
      }
    });

    const stored = window.H5PNetworkCommon.consumeStoredNotice(STORAGE_KEY);
    if (stored) {
      show(stored.type || 'success', stored.message);
    }
  };

  window.H5PNetworkCommon.mount(mount);
})();
