(() => {
  /**
   * Set inner text of HTML element. Will hide the element if no text is set.
   * @param {HTMLElement} element HTML element to set inner text of.
   * @param {string|undefined} text Inner text to set.
   */
  const setInnerText = (element, text) => {
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
   * Set HTML content of element, hide element if no HTML is set.
   * No sanitization here: caller MUST pass HTML purified server-side (restricted wp_kses), as it goes to innerHTML.
   * @param {HTMLElement} element HTML element to set HTML of.
   * @param {string|undefined} html HTML to set, purified by the caller.
   */
  const setHTMLDangerously = (element, html) => {
    if (!(element instanceof HTMLElement)) {
      return;
    }

    if (html) {
      element.innerHTML = html;
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
  const buildDOM = (params = {}) => {
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

    const message = document.createElement('div');
    message.classList.add('h5p-plugin-confirmation-dialog-message');
    body.append(message);

    const buttonsWrapper = document.createElement('div');
    buttonsWrapper.classList.add('h5p-plugin-confirmation-dialog-buttons-wrapper');
    body.append(buttonsWrapper);

    const buttonCancel = document.createElement('button');
    buttonCancel.classList.add('h5p-plugin-confirmation-dialog-button', 'cancel');
    buttonsWrapper.append(buttonCancel);

    const buttonConfirm = document.createElement('button');
    buttonConfirm.classList.add('h5p-plugin-confirmation-dialog-button', 'confirm');
    buttonsWrapper.append(buttonConfirm);

    return { dom, message, buttonCancel, buttonConfirm };
  }

  class H5PPluginConfirmationDialog {
    /**
     * Constructor.
     * @param {object} params Parameters.
     * @param {HTMLElement} [params.parentDOM] DOM element to attach dialog to. Falls back to document.body.
     * @param {object} [params.l10n] Localization.
     * @param {string} [params.l10n.message] Message to show.
     * @param {string} [params.l10n.messageHtml] HTML message, in place of l10n.message. Must be purified server-side.
     * @param {string} [params.l10n.cancel] Label of cancel button. If empty, not cancel button.
     * @param {string} [params.l10n.confirm] Label of confirm button. Falls back to 'OK'.
     * @param {object} [callbacks] Callbacks.
     * @param {function} [callbacks.onCancel] Callback to be called when user cancelled or closed dialog.
     * @param {function} [callbacks.onConfirm] Callback to be called when user confirmed.
     */
    constructor(params = {}, callbacks = {}) {
      // Bound once, so the add/removeEventListener pairs in update() and destroy() always match.
      this.handleCancel = this.handleCancel.bind(this);
      this.handleConfirm = this.handleConfirm.bind(this);

      this.update(params, callbacks);
    }

    /**
     * Close dialog.
     */
    close() {
      this.dom.close();
    }

    /**
     * Show dialog (as modal).
     */
    show() {
      this.dom.showModal();
    }

    /**
     * Handle user canceled or closed dialog.
     */
    handleCancel() {
      this.close();

      this.callbacks.onCancel();
    }

    /**
     * Handle user confirmed.
     */
    handleConfirm() {
      this.close();
      this.callbacks.onConfirm();
    }

    /**
     * Update dialog with new values.
     * @param {object} params Parameters.
     * @param {HTMLElement} [params.parentDOM] DOM element to attach dialog to. Falls back to document.body.
     * @param {object} [params.l10n] Localization.
     * @param {string} [params.l10n.message] Message to show.
     * @param {string} [params.l10n.messageHtml] HTML message, in place of l10n.message. Must be purified server-side.
     * @param {string} [params.l10n.cancel] Label of cancel button. If empty, not cancel button.
     * @param {string} [params.l10n.confirm] Label of confirm button. Falls back to 'OK'.
     * @param {object} [callbacks] Callbacks.
     * @param {function} [callbacks.onCancel] Callback to be called when user cancelled or closed dialog.
     * @param {function} [callbacks.onConfirm] Callback to be called when user confirmed.
     */
    update(params = {}, callbacks = {}) {
      if (this.parentDOM && this.parentDOM.contains(this.dom)) {
        this.dom.removeEventListener('cancel', this.handleCancel);
        this.parentDOM.removeChild(this.dom);
      }

      this.parentDOM = params.parentDOM ?? this.parentDOM ?? document.body;

      // Replaced, not merged: a reused dialog must not keep e.g. the messageHtml of an earlier use.
      this.l10n = {...(params.l10n ?? this.l10n ?? {})};
      this.l10n.message = this.l10n.message ?? '';
      this.l10n.cancel = this.l10n.cancel ?? '';
      this.l10n.confirm = this.l10n.confirm ?? 'OK';

      // A copy, so the caller's object is left alone.
      this.callbacks = {
        onCancel: callbacks.onCancel ?? (() => {}),
        onConfirm: callbacks.onConfirm ?? (() => {}),
      };

      Object.assign(this, buildDOM({
        handleCancel: this.handleCancel,
      }));
      this.parentDOM.append(this.dom);

      this.buttonCancel.addEventListener('click', this.handleCancel);
      this.buttonConfirm.addEventListener('click', this.handleConfirm);

      if (typeof this.l10n.messageHtml === 'string') {
        setHTMLDangerously(this.message, this.l10n.messageHtml);
      }
      else {
        setInnerText(this.message, this.l10n.message);
      }
      setInnerText(this.buttonCancel, this.l10n.cancel);
      setInnerText(this.buttonConfirm, this.l10n.confirm);
    }

    /**
     * Destroy dialog and remove from DOM.
     */
    destroy() {
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
    }
  }

  /**
   * Show dialog, reusing one instance per page, as closed dialogs stay in DOM and fresh ones would accumulate.
   * @param {object} params Parameters, as for the constructor.
   * @param {object} [callbacks] Callbacks, as for the constructor.
   */
  H5PPluginConfirmationDialog.ask = (params, callbacks = {}) => {
    if (!H5PPluginConfirmationDialog.shared) {
      H5PPluginConfirmationDialog.shared = new H5PPluginConfirmationDialog(params, callbacks);
    }
    else {
      H5PPluginConfirmationDialog.shared.update(params, callbacks);
    }
    H5PPluginConfirmationDialog.shared.show();
  };

  window.H5PPluginConfirmationDialog = H5PPluginConfirmationDialog;
})();
