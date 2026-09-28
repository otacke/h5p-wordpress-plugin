/* global H5PEditor H5PNetworkLibraries H5PPluginConfirmationDialog */
/**
 * Behavior of the library grids on the network H5P Management page.
 *
 * The two grids (WAI-ARIA APG data grid pattern) are rendered server-side from
 * the network library table and the hub cache. This adds keyboard navigation,
 * and install, update, deletion, and content upgrades through the core
 * library-install and content-upgrade endpoints and the network delete
 * endpoint; the page reloads afterwards, so the grids rebuild from one
 * server-side state.
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
    const upgradeSettings = settings.upgrade || {};

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

    /**
     * Append a dismissible admin notice below the grids.
     *
     * @param {string} type 'success' or 'error'
     * @param {string|string[]} message One line, or several.
     */
    const showNotice = (type, message) => {
      const notice = document.createElement('div');
      notice.className =
        `notice ${type === 'error' ? 'notice-error' : 'notice-success'} is-dismissible`;

      (Array.isArray(message) ? message : [message]).forEach(line => {
        const text = document.createElement('p');
        text.textContent = line;
        notice.append(text);
      });

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
        // Info does not change state, so it stays usable while something runs.
        if (button.dataset.h5pLibraryAction === 'info') {
          return;
        }

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

    /**
     * Replace the button of a cell with a spinner while an action runs.
     *
     * @param {HTMLElement} cell The grid cell holding the action button.
     * @param {HTMLButtonElement} button The button being replaced.
     * @param {boolean} [showProgress] Also show a progress line below the spinner.
     *
     * @return {{status: (HTMLElement|null), restore: function}} The progress line and a function
     *          that puts the button back and refocuses it.
     */
    const startAction = (cell, button, showProgress) => {
      const children = [];

      const spinner = document.createElement('span');
      spinner.className = 'spinner is-active';
      spinner.setAttribute('role', 'status');
      spinner.setAttribute('aria-label', l10n.working);
      children.push(spinner);

      let status = null;
      if (showProgress) {
        status = document.createElement('span');
        status.className = 'h5p-network-libraries-progress';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        children.push(status);
      }

      const hadFocus = cell.contains(document.activeElement);
      cell.classList.add('h5p-network-libraries-cell--busy');
      cell.replaceChildren(...children);
      if (hadFocus) {
        // The focused button is gone, so keep focus in its cell instead of losing it to the body.
        cell.setAttribute('tabindex', '-1');
        cell.focus();
      }

      setBusy(true);

      return {
        status,
        restore: () => {
          cell.classList.remove('h5p-network-libraries-cell--busy');
          cell.removeAttribute('tabindex');
          cell.replaceChildren(button);
          setBusy(false);
          button.focus();
        }
      };
    };

    /**
     * Store a notice to show after the page reload, then reload it.
     *
     * The page reloads, so sessionStorage is the bridge for the success notice
     * and for the row to focus afterwards.
     *
     * @param {Object} entry What to show and where to return focus.
     */
    const finishWithReload = (entry) => {
      try {
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify(entry));
      }
      catch (error) {
        // Without storage the notice is lost with the reload, but the grids rebuild correctly.
        console.error('H5P network libraries:', error);
      }

      window.location.reload();
    };

    const install = async (button) => {
      const {restore} = startAction(button.parentElement, button);

      try {
        const response = await fetch(ns.getAjaxUrl('library-install', {id: button.dataset.machineName}), {
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
        restore();
        showNotice('error', error.requestError ? error.message : l10n.requestFailed);
        return;
      }

      finishWithReload({
        machineName: button.dataset.machineName,
        message: button.dataset.successMessage
      });
    };

    const deleteLibrary = async (button) => {
      const {restore} = startAction(button.parentElement, button);

      try {
        const body = new FormData();
        body.append('action', 'h5p_network_library_delete');
        body.append('nonce', settings.nonce);
        body.append('id', button.dataset.libraryId);

        const response = await fetch(settings.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body
        });

        let result;
        try {
          result = await response.json();
        }
        catch {
          throw fail(`Unexpected status ${response.status}`);
        }

        if (!response.ok || result.success === false) {
          throw fail(result.message
            ? `${result.message} (${result.errorCode || 'UNKNOWN'})`
            : l10n.deleteFailed);
        }
      }
      catch (error) {
        console.error('H5P network libraries:', error);
        restore();
        showNotice('error', error.requestError ? error.message : l10n.deleteFailed);
        return;
      }

      // The row is gone after the reload, so there is no machineName to focus on.
      finishWithReload({message: button.dataset.successMessage});
    };

    // One dialog instance, since the dialog DOM stays in the document after it
    // is closed and a fresh instance per click would accumulate closed dialogs.
    let confirmDialog;

    /**
     * Ask the user to confirm before a state-changing action is run.
     *
     * @param {HTMLButtonElement} button The clicked action button.
     * @param {function} onConfirm What to run when the user confirms.
     */
    const confirmAction = (button, onConfirm) => {
      const params = {
        l10n: {
          message: button.dataset.confirmMessage,
          cancel: l10n.cancel,
          confirm: button.dataset.confirmLabel || l10n.confirm
        }
      };
      const callbacks = {
        onConfirm
      };

      if (!confirmDialog) {
        confirmDialog = new H5PPluginConfirmationDialog(params, callbacks);
      }
      else {
        confirmDialog.update(params, callbacks);
      }
      confirmDialog.show();
    };

    const loadedScripts = Object.create(null);

    /**
     * Load a plain script from core's h5p-php-library once.
     *
     * @param {string} url
     *
     * @return {Promise<void>}
     */
    const loadScriptOnce = (url) => new Promise((resolve, reject) => {
      if (loadedScripts[url]) {
        resolve();
        return;
      }

      const script = document.createElement('script');
      script.src = url;
      script.onload = () => {
        loadedScripts[url] = true;
        resolve();
      };
      script.onerror = () => reject(fail(l10n.requestFailed));
      document.head.append(script);
    });

    /**
     * Load a library's upgrades script as a plain tag, like core's loadScript.
     *
     * @param {string} url
     * @param {function(boolean)} next
     */
    const loadUpgradeScript = (url, next) => {
      if (loadedScripts[url]) {
        next();
        return;
      }

      const script = document.createElement('script');
      script.src = url;
      script.onload = () => {
        loadedScripts[url] = true;
        next();
      };
      script.onerror = () => next(true);
      document.head.append(script);
    };

    /**
     * Upgrade the contents that use a library to the newest installed version of it.
     *
     * Reimplements the batch loop of core's h5p-content-upgrade.js on top of the
     * existing network-aware endpoints; that script is a closed IIFE, so it can
     * neither be enqueued nor stopped, and it is replaced instead.
     *
     * @param {HTMLButtonElement} button
     */
    const upgradeContents = async (button) => {
      const {status, restore} = startAction(button.parentElement, button, true);

      const machineName = button.dataset.machineName;
      const oldVersion = button.dataset.oldVersion;
      const newVersion = button.dataset.newVersion;
      const total = Number(button.dataset.total) || 0;
      const progressMessage = l10n.inProgress.replace('%ver', newVersion);
      status.textContent = progressMessage;

      // Counts the contents assigned so far; upgraded and skipped go back to the server per batch.
      const state = {
        left: 0,
        token: upgradeSettings.token,
        assigned: 0,
        ids: [],
        parameters: {},
        current: -1,
        working: 0,
        skipped: [],
        upgraded: {}
      };

      const errors = [];

      /**
       * Record a failed content with core's error message for its type.
       *
       * @param {Object|string} error
       */
      const collectError = (error) => {
        if (!error) {
          return;
        }

        let message;

        if (typeof error === 'object') {
          switch (error.type) {
            case 'errorParamsBroken':
              message = l10n.errorContent.replace('%id', error.id) + ' ' + l10n.errorParamsBroken;
              break;
            case 'libraryMissing':
              message = l10n.errorLibrary.replace('%lib', error.library);
              break;
            case 'scriptMissing':
              message = l10n.errorScript.replace('%lib', error.library);
              break;
            case 'errorTooHighVersion':
              message = l10n.errorContent.replace('%id', error.id) + ' '
                + l10n.errorTooHighVersion.replace('%used', error.used).replace('%supported', error.supported);
              break;
            case 'errorNotSupported':
              message = l10n.errorContent.replace('%id', error.id) + ' '
                + l10n.errorNotSupported.replace('%used', error.used);
              break;
            default:
              message = error.message || String(error);
              break;
          }
        }
        else {
          // String errors pass through unchanged, as in core.
          message = error;
        }

        errors.push(l10n.error + ' ' + message);
      };

      const libraryCache = Object.create(null);
      const libraryWaiters = Object.create(null);

      /**
       * Fetch the library data an upgrade runs against, shared by all workers.
       *
       * @param {string} name
       * @param {{major: number, minor: number}} version
       * @param {function(?string, ?Object)} next
       */
      const loadLibrary = (name, version, next) => {
        const key = name + '/' + version.major + '/' + version.minor;

        if (libraryCache[key] === true) {
          libraryWaiters[key].push(next);
          return;
        }

        if (typeof libraryCache[key] === 'object') {
          next(null, libraryCache[key]);
          return;
        }

        libraryCache[key] = true;
        libraryWaiters[key] = [];

        fetch(upgradeSettings.libraryBaseUrl + '/' + key, {
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
          .then(response => (response.ok ? response.json() : Promise.reject(response)))
          .then(library => {
            libraryCache[key] = library;
            const waiters = libraryWaiters[key];
            delete libraryWaiters[key];
            next(null, library);
            waiters.forEach(waiter => waiter(null, library));
          })
          .catch(() => {
            // The failure is not cached, so a later request can retry.
            delete libraryCache[key];
            const waiters = libraryWaiters[key];
            delete libraryWaiters[key];
            const message = l10n.errorData.replace('%lib', name + ' ' + version.major + '.' + version.minor);
            next(message);
            waiters.forEach(waiter => waiter(message));
          });
      };

      const workers = [];
      const terminate = () => workers.forEach(worker => worker.terminate());

      let failedYet = false;

      /**
       * Stop the run, restore the button and report the error.
       *
       * @param {Error} error
       */
      const handleFailure = (error) => {
        if (failedYet) {
          return;
        }
        failedYet = true;

        terminate();
        console.error('H5P network libraries:', error);
        restore();
        showNotice('error', error.requestError ? error.message : l10n.requestFailed);
      };

      /**
       * Report the finished run, then reload so the grids rebuild from the server state.
       */
      const finish = () => {
        terminate();

        const failed = state.skipped.length;
        const succeeded = state.assigned - failed;

        const lines = [
          succeeded === 1 ? l10n.upgradedSingular : l10n.upgradedPlural.replace('%d', String(succeeded))
        ];

        if (failed > 0) {
          lines.push(failed === 1 ? l10n.failedSingular : l10n.failedPlural.replace('%d', String(failed)));
          lines.push(...errors);
        }

        finishWithReload({
          machineName,
          type: failed > 0 ? 'error' : 'success',
          message: lines
        });
      };

      /**
       * Fetch the next batch of contents from the server, or finish if none are left.
       *
       * @return {Promise<void>}
       */
      const requestNextBatch = async () => {
        // The first request carries no skipped or params; the server sends none back yet.
        const body = new URLSearchParams({
          libraryId: button.dataset.targetId,
          token: state.token
        });

        if (state.assigned > 0) {
          body.set('skipped', JSON.stringify(state.skipped));
          body.set('params', JSON.stringify(state.upgraded));
        }

        const response = await fetch(upgradeSettings.progressUrl + button.dataset.libraryId, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body
        });

        // The server answers plain text on errors (e.g. an invalid token), so parse leniently.
        const text = await response.text();
        let inData;

        try {
          inData = JSON.parse(text);
        }
        catch {
          inData = text;
        }

        if (typeof inData !== 'object' || inData === null) {
          throw fail(inData || l10n.requestFailed);
        }

        if (inData.left === 0) {
          finish();
          return;
        }

        state.left = inData.left;
        state.token = inData.token;
        processBatch(inData.params, inData.skipped);
      };

      /**
       * Distribute one fetched batch over the workers, or run it on the main thread.
       *
       * @param {Object} parameters
       * @param {string[]} skipped
       */
      const processBatch = (parameters, skipped) => {
        state.upgraded = {};
        state.skipped = Array.isArray(skipped) ? skipped : [];
        state.parameters = parameters || {};
        state.ids = Object.keys(state.parameters);
        state.current = -1;
        state.assigned += state.ids.length;

        if (workers.length > 0) {
          workers.forEach(worker => assignWork(worker));
        }
        else {
          assignWork();
        }
      };

      /**
       * Give a worker (or the main thread) the next content of the current batch.
       *
       * @param {Worker} [worker]
       *
       * @return {boolean} Whether a job was assigned.
       */
      const assignWork = (worker) => {
        const id = state.ids[state.current + 1];

        if (id === undefined) {
          return false;
        }

        state.current += 1;
        state.working += 1;

        if (worker) {
          worker.postMessage({
            action: 'newJob',
            id,
            name: machineName,
            oldVersion,
            newVersion,
            params: state.parameters[id]
          });
        }
        else {
          runMainJob(id);
        }

        return true;
      };

      /**
       * Run one content upgrade on the main thread when Web Workers are unavailable.
       *
       * @param {string} id
       */
      const runMainJob = (id) => {
        new window.H5P.ContentUpgradeProcess(
          machineName,
          new window.H5P.Version(oldVersion),
          new window.H5P.Version(newVersion),
          state.parameters[id],
          id,
          (name, version, next) => {
            loadLibrary(name, version, (err, library) => {
              if (err) {
                next(err);
                return;
              }

              if (library.upgradesScript) {
                loadUpgradeScript(library.upgradesScript, (scriptError) => {
                  if (scriptError) {
                    next(l10n.errorScript.replace('%lib', name + ' ' + version.major + '.' + version.minor));
                  }
                  else {
                    next(null, library);
                  }
                });
              }
              else {
                next(null, library);
              }
            });
          },
          (err, result) => {
            if (err) {
              collectError(err);
              workDone(id, null);
            }
            else {
              workDone(id, result);
            }
          }
        );
      };

      /**
       * Account for a finished content and feed the next job if one is left.
       *
       * @param {string} id
       * @param {string|null} result
       * @param {Worker} [worker]
       */
      const workDone = (id, result, worker) => {
        state.working -= 1;

        if (result === null) {
          state.skipped.push(id);
        }
        else {
          state.upgraded[id] = result;
        }

        if (status && total > 0) {
          // state.left still counts the batch just returned, so current compensates.
          const percent = Math.round((total - state.left + state.current) / (total / 100));
          status.textContent = progressMessage + ' ' + percent + ' %';
        }

        if (assignWork(worker) === false && state.working === 0) {
          requestNextBatch().catch(handleFailure);
        }
      };

      try {
        if (window.Worker !== undefined) {
          const numWorkers = (window.navigator !== undefined && window.navigator.hardwareConcurrency)
            ? window.navigator.hardwareConcurrency
            : 4;

          for (let index = 0; index < numWorkers; index += 1) {
            const worker = new Worker(upgradeSettings.scriptBaseUrl + '/h5p-content-upgrade-worker.js'
              + upgradeSettings.buster);

            worker.onmessage = (event) => {
              const data = event.data;

              switch (data.action) {
                case 'done':
                  workDone(data.id, data.params, worker);
                  break;
                case 'error':
                  collectError(data.err);
                  workDone(data.id, null, worker);
                  break;
                case 'loadLibrary': {
                  const [major, minor] = data.version.split('.').map(Number);

                  loadLibrary(data.name, {major, minor}, (err, library) => {
                    if (err) {
                      // A worker cannot be told the load failed; a library with null semantics
                      // makes the process report a missing library and free the worker.
                      worker.postMessage({
                        action: 'libraryLoaded',
                        library: {
                          name: data.name,
                          version: {major, minor},
                          semantics: null
                        }
                      });
                      return;
                    }
                    worker.postMessage({action: 'libraryLoaded', library});
                  });
                  break;
                }
              }
            };

            workers.push(worker);
          }
        }
        else {
          // The core scripts assign bare H5P members, so the global must exist first.
          window.H5P = window.H5P || {};
          await loadScriptOnce(upgradeSettings.scriptBaseUrl + '/h5p-version.js' + upgradeSettings.buster);
          await loadScriptOnce(upgradeSettings.scriptBaseUrl + '/h5p-content-upgrade-process.js'
            + upgradeSettings.buster);
        }

        await requestNextBatch();
      }
      catch (error) {
        handleFailure(error);
      }
    };

    // Only available actions carry data-h5p-library-action; placeholders stay inert.
    container.querySelectorAll('[data-h5p-library-action]').forEach(button => {
      button.addEventListener('click', () => {
        if (busy) {
          return;
        }

        switch (button.dataset.h5pLibraryAction) {
          case 'update':
            confirmAction(button, () => install(button));
            break;
          case 'install':
            install(button);
            break;
          case 'upgrade':
            confirmAction(button, () => upgradeContents(button));
            break;
          case 'delete':
            confirmAction(button, () => deleteLibrary(button));
            break;
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
    let hadStoredNotice = false;
    try {
      const stored = sessionStorage.getItem(STORAGE_KEY);
      if (stored) {
        hadStoredNotice = true;
        const storedNotice = JSON.parse(stored);
        const lines = (Array.isArray(storedNotice.message) ? storedNotice.message : [storedNotice.message])
          .filter(Boolean);
        showNotice(storedNotice.type || 'success', lines);
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

    if (focusInstalled) {
      if (pendingFocus) {
        // After the reload that follows an install or upgrade, return focus to the library's row.
        const rows = [...installedGrid.querySelectorAll('[role="row"]')]
          .filter(row => row.dataset.library === pendingFocus);
        // Versions sort ascending, so the just installed or upgraded one is the newest, i.e. last.
        if (rows.length) {
          focusInstalled(rows[rows.length - 1].firstElementChild);
        }
      }
      else if (hadStoredNotice) {
        // After a deletion the row is gone, so return focus to the first data row.
        const rows = installedGrid.querySelectorAll('[role="row"]');
        if (rows.length > 1) {
          focusInstalled(rows[1].firstElementChild);
        }
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
