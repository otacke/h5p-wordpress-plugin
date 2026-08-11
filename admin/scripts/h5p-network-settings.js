(() => {
  /**
   * Build confirmation dialog.
   * @params {boolean} enabled True if network mode is currently enabled, ense false.
   * @returns {H5PPluginConfirmationDialog} Confirmation Dialog instance.
   */
  const buildConfirmationDialog = (enabled) => {
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
        setNetworkEnabled(!enabled);
      },
    };

    return new window.H5PPluginConfirmationDialog(dialogParams, dialogCallbacks);
  };

  /**
   * Call a migration endpoint.
   * @param {string} action The AJAX action name.
   */
  const callMigrationEndpoint = (action) => {
    const nonce = window.H5PNetworkSettingsProperties.nonce;
    if (!nonce) {
      console.error('H5P network nonce not available.');
      return;
    }

    const formData = new FormData();
    formData.append('action', action);
    formData.append('nonce', nonce);

    fetch(window.H5PNetworkSettingsProperties.ajaxPath, {
      method: 'POST',
      body: formData,
    })
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          window.location.reload();
        } else {
          console.error('Migration failed:', result.data);
        }
      })
      .catch(error => {
        console.error('Migration request failed:', error);
      });
  };

  /**
   * Trigger migration of files / databases to network level.
   */
  const triggerMigrationToNetwork = () => {
    console.log('triggerMigrationToNetwork');
    callMigrationEndpoint('h5p_migrate_to_network');
  };

  /**
   * Trigger migration of files / databases back to blog level.
   */
  const triggerMigrationToLocal = () => {
    console.log('triggerMigrationToLocal');
    callMigrationEndpoint('h5p_migrate_to_local');
  };

  /**
   * Set state of enables network.
   * @param {boolean} state State to set.
   */
  const setNetworkEnabled = (state) => {
    if (state === true) {
      triggerMigrationToNetwork();
    }
    else if (state === false) {
      triggerMigrationToLocal();
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

  const confirmationDialog = buildConfirmationDialog(h5pNetworkToggleInput.checked);

  h5pNetworkToggleButton.addEventListener('click', () => {
    confirmationDialog.show();
  });
})()
