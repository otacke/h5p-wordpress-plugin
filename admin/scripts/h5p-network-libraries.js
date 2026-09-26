/* global H5PEditor H5PNetworkLibraries H5PPluginConfirmationDialog */
/**
 * Behavior of the library grids on the network H5P Management page.
 *
 * The two grids (WAI-ARIA APG data grid pattern) are rendered server-side from
 * the network library table and the hub cache. This adds keyboard navigation,
 * and install and update through the core library-install endpoint; the page
 * reloads afterwards, so the grids rebuild from one server-side state.
 */
((ns) => {
  const STORAGE_KEY = 'h5p-network-libraries-notice';

  const mount = () => {
    const container = document.querySelector('.h5p-network-libraries');
    if (!container) {
      return;
    }

    // typeof guard, not optional chaining: the global is absent if print_settings emitted nothing.
    const settings = typeof H5PNetworkLibraries !== 'undefined' ? H5PNetworkLibraries : {};
    const l10n = settings.l10n || {};

    const noticesRegion = container.querySelector('.h5p-network-libraries-notices');
    const installedGrid = container.querySelector('.h5p-network-libraries-installed [role="grid"]');
    const availableGrid = container.querySelector('.h5p-network-libraries-available [role="grid"]');

    // One request at a time: core installs share the temporary upload folder.
    let busy = false;

    /**
     * Add APG grid keyboard navigation with a roving tabindex.
     *
     * Cells holding a button delegate focus and keys to the button, the only
     * focusable child a cell can have.
     *
     * @param {HTMLElement} grid
     *
     * @return {function(HTMLElement)} Focus a data cell of the grid.
     */
    const makeGridNavigable = (grid) => {
      // The first row is the header and stays out of navigation.
      const cells = [...grid.querySelectorAll('[role="row"]')]
        .slice(1)
        .flatMap(row => [...row.children]);
      const columns = cells.length ? cells[0].parentElement.children.length : 0;
      const targets = cells.map(cell => cell.querySelector('button') || cell);

      const focusTarget = (index) => {
        targets.forEach((target, i) => target.setAttribute('tabindex', i === index ? '0' : '-1'));
        targets[index].focus();
      };

      targets.forEach((target, index) => {
        target.addEventListener('keydown', (event) => {
          let next;

          switch (event.key) {
            case 'ArrowLeft':
              next = index - 1;
              break;
            case 'ArrowRight':
              next = index + 1;
              break;
            case 'ArrowUp':
              next = index - columns;
              break;
            case 'ArrowDown':
              next = index + columns;
              break;
            case 'PageUp':
              next = index - 10 * columns;
              break;
            case 'PageDown':
              next = index + 10 * columns;
              break;
            case 'Home':
              next = event.ctrlKey || event.metaKey
                ? 0
                : Math.floor(index / columns) * columns;
              break;
            case 'End':
              next = event.ctrlKey || event.metaKey
                ? cells.length - 1
                : Math.floor(index / columns) * columns + columns - 1;
              break;
            default:
              return;
          }

          if (next < 0 || next >= cells.length) {
            return;
          }

          event.preventDefault();
          focusTarget(next);
        });
      });

      // Buttons are in the tab sequence by default, so all but the first target are taken out.
      targets.forEach((target, i) => target.setAttribute('tabindex', i === 0 ? '0' : '-1'));

      return (cell) => {
        const index = cells.indexOf(cell);
        if (index !== -1) {
          focusTarget(index);
        }
      };
    };

    const showNotice = (type, message) => {
      const notice = document.createElement('div');
      notice.className =
        `notice ${type === 'error' ? 'notice-error' : 'notice-success'} is-dismissible`;

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

      noticesRegion.append(notice);
    };

    const setBusy = (value) => {
      busy = value;
      container.querySelectorAll('[data-h5p-library-action]').forEach(button => {
        if (value) {
          button.classList.add('h5p-icon-button-disabled');
          button.setAttribute('aria-disabled', 'true');
        }
        else {
          button.classList.remove('h5p-icon-button-disabled');
          button.removeAttribute('aria-disabled');
        }
      });
    };

    const fail = (message) => {
      const error = new Error(message);
      error.requestError = true;
      return error;
    };

    const install = async (button) => {
      const cell = button.parentElement;

      const spinner = document.createElement('span');
      spinner.className = 'spinner is-active';
      spinner.setAttribute('role', 'status');
      spinner.setAttribute('aria-label', l10n.working);
      const hadFocus = cell.contains(document.activeElement);
      cell.replaceChildren(spinner);
      if (hadFocus) {
        // The focused button is gone, so keep focus in its cell instead of losing it to the body.
        cell.setAttribute('tabindex', '-1');
        cell.focus();
      }

      setBusy(true);

      try {
        const response = await fetch(ns.getAjaxUrl('library-install', { id: button.dataset.machineName }), {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        let body;
        try {
          body = await response.json();
        }
        catch {
          throw fail(`Unexpected status ${response.status}`);
        }

        if (!response.ok || body.success === false) {
          throw fail(body.message
            ? `${body.message} (${body.errorCode || 'UNKNOWN'})`
            : l10n.requestFailed);
        }
      }
      catch (error) {
        console.error('H5P network libraries:', error);
        cell.removeAttribute('tabindex');
        cell.replaceChildren(button);
        setBusy(false);
        button.focus();
        showNotice('error', error.requestError ? error.message : l10n.requestFailed);
        return;
      }

      // The page reloads below, so sessionStorage is the bridge for the
      // success notice and for the row to focus afterwards.
      try {
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
          machineName: button.dataset.machineName,
          message: button.dataset.successMessage
        }));
      }
      catch (error) {
        // Without storage the notice is lost with the reload, but the grids rebuild correctly.
        console.error('H5P network libraries:', error);
      }

      window.location.reload();
    };

    // One dialog instance, since the dialog DOM stays in the document after it
    // is closed and a fresh instance per click would accumulate closed dialogs.
    let updateDialog;

    /**
     * Ask the user to confirm an update before it is installed.
     *
     * @param {HTMLButtonElement} button
     */
    const confirmUpdate = (button) => {
      const params = {
        l10n: {
          message: button.dataset.confirmMessage,
          cancel: l10n.cancel,
          confirm: l10n.confirm
        }
      };
      const callbacks = {
        onConfirm: () => install(button)
      };

      if (!updateDialog) {
        updateDialog = new H5PPluginConfirmationDialog(params, callbacks);
      }
      else {
        updateDialog.update(params, callbacks);
      }
      updateDialog.show();
    };

    // Only available actions carry data-h5p-library-action; placeholders stay inert.
    container.querySelectorAll('[data-h5p-library-action]').forEach(button => {
      button.addEventListener('click', () => {
        if (busy) {
          return;
        }

        if (button.dataset.h5pLibraryAction === 'update') {
          confirmUpdate(button);
        }
        else {
          install(button);
        }
      });
    });

    const focusInstalled = installedGrid ? makeGridNavigable(installedGrid) : null;
    if (availableGrid) {
      makeGridNavigable(availableGrid);
    }

    // A successful install or update reloaded the page; the stored notice and
    // target row are consumed once.
    let pendingFocus = null;
    try {
      const stored = sessionStorage.getItem(STORAGE_KEY);
      if (stored) {
        const storedNotice = JSON.parse(stored);
        showNotice('success', storedNotice.message);
        pendingFocus = storedNotice.machineName || null;
      }
    }
    catch {
      // sessionStorage unavailable (e.g. private mode); the grids still work.
    }
    try {
      // Remove even if reading failed, so a stale entry cannot resurface later.
      sessionStorage.removeItem(STORAGE_KEY);
    }
    catch {
      // Nothing to do; the entry, if any, stays harmlessly stored.
    }

    // After the reload that follows an install, return focus to the installed library's row.
    if (pendingFocus && focusInstalled) {
      const rows = [...installedGrid.querySelectorAll('[role="row"]')]
        .filter(row => row.dataset.library === pendingFocus);
      // Versions sort ascending, so the just installed one is the newest, i.e. last.
      if (rows.length) {
        focusInstalled(rows[rows.length - 1].firstElementChild);
      }
    }
  };

  // Script is enqueued while page body renders, so DOMContentLoaded may already have fired.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  }
  else {
    mount();
  }
})(H5PEditor);
