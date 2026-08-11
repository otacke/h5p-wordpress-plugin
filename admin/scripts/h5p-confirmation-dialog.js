(() => {
  /**
   * Create UUID string using Web Crypto API if available.
   * @returns {string} UUID string.
   */
  const createUUID = () => {
    if (typeof window.crypto?.randomUUID === 'function') {
      return window.crypto.randomUUID();
    }

    const UUID_TEMPLATE = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx';
    const RANDOM_PLACEHOLDER = 'x';
    const UUID_REPLACE_PATTERN = /[xy]/g;
    const HEX_RADIX = 16;
    const VARIANT_MASK = 0x3;
    const VARIANT_FLAG = 0x8;

    return UUID_TEMPLATE.replace(UUID_REPLACE_PATTERN, (char) => {
      const random = (Math.random() * HEX_RADIX) | 0;
      const newChar = char === RANDOM_PLACEHOLDER ? random : (random & VARIANT_MASK) | VARIANT_FLAG;
      return newChar.toString(HEX_RADIX);
    });
  };

  /**
   * Set inner text of HTML element. Will hide the element if no text is set.
   * @param {HTMLElement} element HTML element to set inner text of.
   * @param {string|undefined} text Inner text to set.
   */
  setInnerText = (element, text) => {
    if (!(element instanceof HTMLElement)) {
      return;
    }

    if (text) {
      element.innerText = text;
      element.classList.remove('display-none');
    }
    else {
      element.classList.add('display-none');
    }
  };

  /**
   * Build DOM eleemnts.
   * @param {object} params Parameters.
   * @returns {object} Dom elements.
   */
  buildDOM = (params = {}) => {
    const dom = document.createElement('dialog');
    dom.setAttribute('closedby', 'any');
    dom.classList.add('h5p-plugin-confirmation-dialog');
    dom.addEventListener('cancel', params.handleCancel);

    const content = document.createElement('div');
    content.classList.add('h5p-plugin-confirmation-dialog-content');
    dom.append(content);

    const body = document.createElement('div');
    body.classList.add('h5p-plugin-confirmation-dialog-body');
    content.append(body);

    const message = document.createElement('span');
    message.classList.add('h5p-plugin-confirmation-dialog-message');
    body.append(message);

    const buttonsWrapper = document.createElement('div');
    buttonsWrapper.classList.add('h5p-plugin-confirmation-dialog-buttons-wrapper');
    body.append(buttonsWrapper);

    buttonCancel = document.createElement('button');
    buttonCancel.classList.add('h5p-plugin-confirmation-dialog-button', 'cancel');
    buttonsWrapper.append(buttonCancel);

    buttonConfirm = document.createElement('button');
    buttonConfirm.classList.add('h5p-plugin-confirmation-dialog-button', 'confirm');
    buttonsWrapper.append(buttonConfirm);

    return { dom, message, buttonCancel, buttonConfirm };
  }

  /**
   * Constructor.
   * @param {object} params Parameters.
   * @param {HTMLElement} [params.parentDOM] DOM element to attach dialog to. Falls back to document.body.
   * @param {object} [params.l10n] Localization.
   * @param {string} [params.l10n.message] Message to show.
   * @param {string} [params.l10n.cancel] Label of cancel button. If empty, not cancel button.
   * @param {string} [params.l10n.confirm] Label of confirm button. Falls back to 'OK'.
   * @param {object} [callbacks] Callbacks.
   * @param {function} [callbacks.onCancel] Callback to be called when user cancelled or closed dialog.
   * @param {function} [callbacks.onConfirm] Callback to be called when user confirmed.
   */
  window.H5PPluginConfirmationDialog = function (params = {}, callbacks = {}) {
    /**
     * Close dialog.
     */
    this.close = () => {
      this.dom.close();
    }

    /**
     * Show dialog (as modal).
     */
    this.show = () => {
      this.dom.showModal();
    };

    /**
     * Handle user canceled or closed dialog.
     */
    this.handleCancel = () => {
      this.close();

      this.callbacks.onCancel();
    }

    /**
     * Handle user confirmed.
     */
    this.handleConfirm = () => {
      this.close();
      this.callbacks.onConfirm();
    }

    /**
     * Update dialog with new values.
     * @param {object} params Parameters.
     * @param {HTMLElement} [params.parentDOM] DOM element to attach dialog to. Falls back to document.body.
     * @param {object} [params.l10n] Localization.
     * @param {string} [params.l10n.message] Message to show.
     * @param {string} [params.l10n.cancel] Label of cancel button. If empty, not cancel button.
     * @param {string} [params.l10n.confirm] Label of confirm button. Falls back to 'OK'.
     * @param {object} [callbacks] Callbacks.
     * @param {function} [callbacks.onCancel] Callback to be called when user cancelled or closed dialog.
     * @param {function} [callbacks.onConfirm] Callback to be called when user confirmed.
     */
    this.update = (params = {}, callbacks = {}) => {
      if (this.parentDOM && this.parentDOM.contains(this.dom)) {
        this.dom.removeEventListener('cancel', this.handleCancel);
        this.parentDOM.removeChild(this.dom);
      }

      this.parentDOM = params.parentDOM ?? this.parentDOM ?? document.body;

      this.l10n = params.l10n ?? this.l10n ?? {};
      this.l10n.message = params.l10n?.message ?? this.l10n.message ?? '';
      this.l10n.cancel = params.l10n?.cancel ?? this.l10n.cancel ?? '';
      this.l10n.confirm = params.l10n?.confirm ?? this.l10n.confirm ?? 'OK';

      this.callbacks = callbacks ?? this.callbacks ?? {};
      this.callbacks.onCancel = this.callbacks.onCancel ?? (() => {});
      this.callbacks.onConfirm = this.callbacks.onConfirm ?? (() => {});

      Object.assign(this, buildDOM({
        handleCancel: this.handleCancel,
      }));
      this.parentDOM.append(this.dom);

      this.buttonCancel.addEventListener('click', this.handleCancel);
      this.buttonConfirm.addEventListener('click', this.handleConfirm);

      setInnerText(this.message, this.l10n.message);
      setInnerText(this.buttonCancel, this.l10n.cancel);
      setInnerText(this.buttonConfirm, this.l10n.confirm);
    };

    /**
     * Destroy dialog and remove from DOM.
     */
    this.destroy = () => {
      if (this.parentDOM && this.parentDOM.contains(this.dom)) {
        this.parentDOM.removeChild(this.dom);
      }

      this.dom.removeEventListener('cancel', this.handleCancel);
      this.buttonCancel.removeEventListener('click', this.handleCancel);
      this.buttonConfirm.removeEventListener('click', this.handleConfirm);

      this.l10n = null;

      this.dom = null;
      this.message = null;
      this.buttonCancel = null;
      this.buttonConfirm = null;
      this.parentDOM = null;
    };

    this.update(params, callbacks);
  };
})();
