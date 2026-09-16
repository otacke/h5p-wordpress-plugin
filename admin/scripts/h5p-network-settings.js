(() => {
  /**
   * Build confirmation dialog.
   * @params {boolean} enabled True if network mode is currently enabled, ense false.
   * @params {HTMLButtonElement} button Toggle button to keep in sync.
   * @returns {H5PPluginConfirmationDialog} Confirmation Dialog instance.
   */
  const buildConfirmationDialog = (enabled, button) => {
    const props = window.H5PNetworkSettingsProperties;

    const dialogParams = {
      parentDOM: document.querySelector('#wpcontent'),
      l10n: {
        cancel: props.networkToggleConfirmationDialogCancelLabel,
        confirm: props.networkToggleConfirmationDialogConfirmLabel,
        message: enabled ?
          props.networkToggleConfirmationDialogMessageDisable :
          props.networkToggleConfirmationDialogMessageEnable,
      },
    };

    const dialogCallbacks = {
      onConfirm: () => {
        setNetworkEnabled(!enabled, button);
      },
    };

    return new window.H5PPluginConfirmationDialog(dialogParams, dialogCallbacks);
  };

  /**
   * Show an error notice on the settings page.
   * @param {string} message Message to display. Inserted as text, not HTML.
   */
  const showErrorNotice = (message) => {
    const wrap = document.querySelector('.wrap.h5p-settings-container');
    if (!wrap) {
      return;
    }

    const existingNotice = wrap.querySelector('.h5p-network-migration-notice');
    if (existingNotice) {
      existingNotice.remove();
    }

    const notice = document.createElement('div');
    notice.className = 'error notice h5p-network-migration-notice';

    const paragraph = document.createElement('p');
    paragraph.textContent = message;
    notice.appendChild(paragraph);

    const heading = wrap.querySelector('h2');
    if (heading) {
      wrap.insertBefore(notice, heading);
    }
    else {
      wrap.prepend(notice);
    }

    notice.scrollIntoView({ block: 'center' });
  };

  /**
   * Set the busy state of the toggle button.
   * @param {HTMLButtonElement} button Toggle button.
   * @param {boolean} busy True while a migration request is running.
   */
  const setButtonBusy = (button, busy) => {
    if (busy) {
      if (button.dataset.originalLabel === undefined) {
        button.dataset.originalLabel = button.textContent;
      }
      button.textContent = window.H5PNetworkSettingsProperties.migrationInProgress;
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');
    }
    else {
      if (button.dataset.originalLabel) {
        button.textContent = button.dataset.originalLabel;
      }
      button.disabled = false;
      button.removeAttribute('aria-busy');
    }
  };

  /**
   * Show how far the migration has got on the button.
   * @param {HTMLButtonElement} button Toggle button.
   * @param {object} progress Progress from the server.
   */
  const setButtonProgress = (button, progress) => {
    const props = window.H5PNetworkSettingsProperties;

    if (typeof progress.percentage !== 'number') {
      return;
    }

    button.textContent = props.migrationProgress
      .replace('%percentage', progress.percentage);
  };

  /**
   * Call a migration endpoint.
   * @param {string} action The AJAX action name.
   * @param {HTMLButtonElement} button Toggle button to keep in sync.
   */
  const callMigrationEndpoint = (action, button) => {
    const props = window.H5PNetworkSettingsProperties;

    if (!props.nonce) {
      console.error('H5P network nonce not available.');
      return;
    }

    setButtonBusy(button, true);

    // The migration works through the blogs in batches, so keep calling until
    // the server reports it is done.
    const runBatch = (nonce) => {
      const formData = new FormData();
      formData.append('action', action);
      formData.append('nonce', nonce);

      fetch(props.ajaxPath, {
        method: 'POST',
        body: formData,
      })
        .then(response => response.json())
        .then(result => {
          if (result.success) {
            const progress = result.data || {};

            if (progress.done === false) {
              setButtonProgress(button, progress);
              // A fresh nonce comes back with every batch, so a long
              // migration cannot fail on an expired one.
              runBatch(progress.nonce || nonce);
              return;
            }

            window.location.reload();
            return;
          }

          const data = result.data || {};
          const summary = data.rolledBack ?
            props.migrationFailedRolledBack :
            props.migrationFailedNotRolledBack;

          setButtonBusy(button, false);
          showErrorNotice(data.message ? `${summary} ${data.message}` : summary);
        })
        .catch(error => {
          console.error('Migration request failed:', error);

          setButtonBusy(button, false);
          showErrorNotice(props.migrationRequestFailed);
        });
    };

    runBatch(props.nonce);
  };

  /**
   * Trigger migration of files / databases to network level.
   * @param {HTMLButtonElement} button Toggle button to keep in sync.
   */
  const triggerMigrationToNetwork = (button) => {
    callMigrationEndpoint('h5p_migrate_to_network', button);
  };

  /**
   * Trigger migration of files / databases back to blog level.
   * @param {HTMLButtonElement} button Toggle button to keep in sync.
   */
  const triggerMigrationToLocal = (button) => {
    callMigrationEndpoint('h5p_migrate_to_local', button);
  };

  /**
   * Set state of enables network.
   * @param {boolean} state State to set.
   * @param {HTMLButtonElement} button Toggle button to keep in sync.
   */
  const setNetworkEnabled = (state, button) => {
    if (state === true) {
      triggerMigrationToNetwork(button);
    }
    else if (state === false) {
      triggerMigrationToLocal(button);
    }
  };

  const h5pNetworkToggleInput = document.querySelector('#h5p_network_toggle_input');
  if (!(h5pNetworkToggleInput instanceof HTMLInputElement)) {
    return;
  }

  const h5pNetworkToggleButton = document.querySelector('#h5p_network_toggle_button');
  if (!(h5pNetworkToggleButton instanceof HTMLButtonElement)) {
    return;
  }

  const confirmationDialog = buildConfirmationDialog(
    h5pNetworkToggleInput.checked,
    h5pNetworkToggleButton
  );

  h5pNetworkToggleButton.addEventListener('click', () => {
    confirmationDialog.show();
  });
})()
