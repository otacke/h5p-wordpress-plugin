/* global H5PNetworkRestrictions H5PPluginConfirmationDialog */
/**
 * Behavior of the network H5P Restrictions page.
 *
 * The page is rendered server-side. This saves the checkboxes changed on the
 * current page, and runs the bulk actions on all sites matching the search,
 * through the network restrictions endpoints; the page reloads afterwards, so
 * the counts rebuild from one server-side state.
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
      const notice = document.createElement('div');
      notice.className = `notice ${type === 'error' ? 'notice-error' : 'notice-success'} is-dismissible`;

      const text = document.createElement('p');
      text.textContent = message;
      notice.append(text);

      // The WordPress default dismiss button; the x is drawn by .notice-dismiss::before.
      const dismiss = document.createElement('button');
      dismiss.type = 'button';
      dismiss.className = 'notice-dismiss';
      const srText = document.createElement('span');
      srText.className = 'screen-reader-text';
      srText.textContent = l10n.dismiss;
      dismiss.append(srText);
      dismiss.addEventListener('click', () => notice.remove());
      notice.append(dismiss);

      region.append(notice);
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
    const post = async (action, body) => {
      body.set('action', action);
      body.set('nonce', settings.nonce);

      const response = await fetch(settings.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body
      });

      let json;
      try {
        json = await response.json();
      }
      catch {
        throw new Error(l10n.requestFailed);
      }

      if (!response.ok || !json.success) {
        throw new Error((json.data && json.data.message) || l10n.requestFailed);
      }

      return json.data;
    };

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

      try {
        sessionStorage.setItem(STORAGE_KEY, l10n.saved);
      }
      catch (error) {
        // Without storage the notice is lost with the reload, but the page rebuilds correctly.
        console.error('H5P network restrictions:', error);
      }

      // Nothing is unsaved anymore, so leaving must not warn.
      checkboxes.forEach(checkbox => {
        checkbox.defaultChecked = checkbox.checked;
      });
      window.location.reload();
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

    let dialog;

    /**
     * Ask the user to confirm a bulk action, then run it.
     *
     * The first use creates the dialog, later uses update and re-show it, so
     * closed dialogs do not accumulate in the document.
     *
     * @param {HTMLButtonElement} button
     */
    const apply = (button) => {
      const params = {
        l10n: {
          message: button.dataset.confirmMessage,
          cancel: l10n.cancel,
          confirm: button.dataset.confirmLabel
        }
      };
      const callbacks = {
        onConfirm: () => run(() => post('h5p_network_restrictions_apply', new URLSearchParams({
          library: button.dataset.library,
          s: button.dataset.search,
          restricted: button.dataset.h5pRestrictionsApply
        })))
      };

      if (!dialog) {
        dialog = new H5PPluginConfirmationDialog(params, callbacks);
      }
      else {
        dialog.update(params, callbacks);
      }
      dialog.show();
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

    let stored = null;
    try {
      stored = sessionStorage.getItem(STORAGE_KEY);
      sessionStorage.removeItem(STORAGE_KEY);
    }
    catch {
      // Without storage there is no notice to restore.
    }
    if (stored) {
      show('success', stored);
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  }
  else {
    mount();
  }
})();
