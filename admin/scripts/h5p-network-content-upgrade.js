/**
 * Content upgrades for the network H5P management page.
 *
 * Exposes H5PNetworkContentUpgrade, whose create() builds the upgrade runner
 * the page script mounts, from the page's upgradeSettings, l10n, and fail
 * helper. Enqueued before the page script.
 */
window.H5PNetworkContentUpgrade = {
  /**
   * Build the upgrade runner for one page.
   *
   * @param {object} config
   * @param {object} config.upgradeSettings From H5PNetworkLibraries.upgrade.
   * @param {object} config.l10n From H5PNetworkLibraries.l10n.
   * @param {function(string): Error} config.fail Builds an error, the page script's fail.
   *
   * @return {function} The runContentUpgrade for this page.
   */
  create: ({upgradeSettings, l10n, fail}) => {
    const loadedScripts = Object.create(null);

    /**
     * Load a plain script once, like core's loadScript: a core upgrade script or a library's upgrades script.
     *
     * Calls that arrive while the script loads share its promise. A failed load is not cached, so a
     * later call re-attempts it.
     *
     * @param {string} url
     *
     * @return {Promise<void>}
     */
    const loadScript = (url) => {
      if (loadedScripts[url] === true) {
        return Promise.resolve();
      }
      if (typeof loadedScripts[url] === 'object') {
        return loadedScripts[url];
      }

      loadedScripts[url] = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = url;
        script.onload = () => {
          loadedScripts[url] = true;
          resolve();
        };
        script.onerror = () => {
          delete loadedScripts[url];
          reject(fail(l10n.requestFailed));
        };
        document.head.append(script);
      });

      return loadedScripts[url];
    };

    // Library data for the upgrades, shared by all runs of the page, as it does not change until the reload.
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
      const key = `${name}/${version.major}/${version.minor}`;

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

      fetch(`${upgradeSettings.libraryBaseUrl}/${key}`, {
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
          const message = l10n.errorData.replace('%lib', `${name} ${version.major}.${version.minor}`);
          next(message);
          waiters.forEach(waiter => waiter(message));
        });
    };

    /**
     * Upgrade the contents that use a library version to another installed version of it.
     *
     * Reimplements the batch loop of core's h5p-content-upgrade.js on top of the
     * existing network-aware endpoints; that script is a closed IIFE, so it can
     * neither be enqueued nor stopped, and it is replaced instead.
     *
     * Creates its own workers and terminates them when done. Contents upgraded in
     * earlier batches stay saved on the server if the run fails later.
     *
     * @param {Object} job
     * @param {string} job.sourceId Id of the library version the contents use now.
     * @param {string} job.targetId Id of the library version to upgrade to.
     * @param {string} job.machineName
     * @param {string} job.oldVersion major.minor of the source.
     * @param {string} job.newVersion major.minor of the target.
     * @param {number} job.total Number of contents to upgrade, for the progress.
     * @param {function(number)} onProgress Called with the percentage done.
     *
     * @return {Promise<{assigned: number, failed: number, errors: string[]}>} Rejects on a fatal error.
     */
    const runContentUpgrade = (job, onProgress) => new Promise((resolve, reject) => {
      const {machineName, oldVersion, newVersion, total} = job;

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
              message = `${l10n.errorContent.replace('%id', error.id)} ${l10n.errorParamsBroken}`;
              break;
            case 'libraryMissing':
              message = l10n.errorLibrary.replace('%lib', error.library);
              break;
            case 'scriptMissing':
              message = l10n.errorScript.replace('%lib', error.library);
              break;
            case 'errorTooHighVersion': {
              const detail = l10n.errorTooHighVersion.replace('%used', error.used).replace('%supported', error.supported);
              message = `${l10n.errorContent.replace('%id', error.id)} ${detail}`;
              break;
            }
            case 'errorNotSupported': {
              const detail = l10n.errorNotSupported.replace('%used', error.used);
              message = `${l10n.errorContent.replace('%id', error.id)} ${detail}`;
              break;
            }
            default:
              message = error.message || String(error);
              break;
          }
        }
        else {
          // String errors pass through unchanged, as in core.
          message = error;
        }

        errors.push(`${l10n.error} ${message}`);
      };

      const workers = [];
      const terminate = () => workers.forEach(worker => worker.terminate());

      let failedYet = false;

      /**
       * Stop the run with a fatal error.
       *
       * @param {Error} error
       */
      const handleFailure = (error) => {
        if (failedYet) {
          return;
        }
        failedYet = true;

        terminate();
        reject(error);
      };

      /**
       * Stop the run with its outcome.
       */
      const finish = () => {
        terminate();
        resolve({assigned: state.assigned, failed: state.skipped.length, errors});
      };

      /**
       * Fetch the next batch of contents from the server, or finish if none are left.
       *
       * @return {Promise<void>}
       */
      const requestNextBatch = async () => {
        // The first request carries no skipped or params; the server sends none back yet.
        const body = new URLSearchParams({
          libraryId: job.targetId,
          token: state.token
        });

        if (state.assigned > 0) {
          body.set('skipped', JSON.stringify(state.skipped));
          body.set('params', JSON.stringify(state.upgraded));
        }

        const response = await fetch(`${upgradeSettings.progressUrl}${job.sourceId}`, {
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
                loadScript(library.upgradesScript).then(
                  () => next(null, library),
                  () => next(l10n.errorScript.replace('%lib', `${name} ${version.major}.${version.minor}`))
                );
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

        if (total > 0) {
          // state.left still counts the batch just returned, so current compensates.
          onProgress(Math.round((total - state.left + state.current) / (total / 100)));
        }

        if (assignWork(worker) === false && state.working === 0) {
          requestNextBatch().catch(handleFailure);
        }
      };

      /**
       * Create the workers, or load the scripts for the main thread, and fetch the first batch.
       *
       * @return {Promise<void>}
       */
      const start = async () => {
        if (window.Worker !== undefined) {
          const numWorkers = (window.navigator !== undefined && window.navigator.hardwareConcurrency)
            ? window.navigator.hardwareConcurrency
            : 4;

          for (let index = 0; index < numWorkers; index += 1) {
            const worker = new Worker(`${upgradeSettings.scriptBaseUrl}/h5p-content-upgrade-worker.js${upgradeSettings.buster}`);

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
          await loadScript(`${upgradeSettings.scriptBaseUrl}/h5p-version.js${upgradeSettings.buster}`);
          await loadScript(`${upgradeSettings.scriptBaseUrl}/h5p-content-upgrade-process.js${upgradeSettings.buster}`);
        }

        await requestNextBatch();
      };

      start().catch(handleFailure);
    });

    return runContentUpgrade;
  }
};
