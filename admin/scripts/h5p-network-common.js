/**
 * Helpers shared by the network H5P admin pages (settings, management, restrictions): mounting, notices,
 * requests to the network AJAX endpoints, and a notice carried across the page reload that follows a change.
 *
 * window.H5PNetworkCommon
 */
(() => {
  /**
   * Run a page script once the DOM is ready. Scripts are enqueued while the page body renders, so
   * DOMContentLoaded may already have fired.
   *
   * @param {function} callback
   */
  const mount = (callback) => {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', callback);
    }
    else {
      callback();
    }
  };

  /**
   * Append a dismissible admin notice.
   *
   * @param {HTMLElement} target Where to append the notice.
   * @param {string} type 'success' or 'error'
   * @param {string|string[]} message One line, or several.
   * @param {string} dismissLabel Screen reader label of the dismiss button.
   */
  const showNotice = (target, type, message, dismissLabel) => {
    const notice = document.createElement('div');
    notice.className = `notice ${type === 'error' ? 'notice-error' : 'notice-success'} is-dismissible`;

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
    srText.textContent = dismissLabel;
    dismiss.append(srText);
    dismiss.addEventListener('click', () => notice.remove());
    notice.append(dismiss);

    target.append(notice);
  };

  /**
   * Build the error of a failed request: requestError marks it as one, so callers can tell it from a bug.
   *
   * @param {string} message
   * @param {string[]} [lines] The server's error lines, if several.
   * @param {Object} [data] The server's error data, e.g. rolledBack.
   *
   * @return {Error}
   */
  const requestError = (message, lines, data) => {
    const error = new Error(message);
    error.requestError = true;
    if (lines) {
      error.lines = lines;
    }
    error.data = data || {};
    return error;
  };

  /**
   * Post to a network AJAX endpoint.
   *
   * @param {Object} settings With ajaxUrl and nonce.
   * @param {string} action The wp_ajax action.
   * @param {FormData|URLSearchParams} body Fields to send along.
   * @param {string} fallback Error message if the response carries none.
   *
   * @return {Promise<Object>} The data of the successful response.
   */
  const postAjax = async (settings, action, body, fallback) => {
    body.append('action', action);
    body.append('nonce', settings.nonce);

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
      throw requestError(`Unexpected status ${response.status}`);
    }

    if (!response.ok || !result || result.success !== true) {
      // A failed nonce check answers -1, so the data may be missing altogether.
      const data = (result && result.data) || {};
      const errors = data.messages && data.messages.error && data.messages.error.length
        ? data.messages.error
        : [data.message || fallback];
      throw requestError(errors.join(' '), errors, data);
    }

    return result.data || {};
  };

  /**
   * Store a notice to show after the page reload, then reload.
   *
   * The page reloads, so sessionStorage is the bridge for the notice and whatever else the page needs then.
   *
   * @param {string} key Storage key of the page.
   * @param {Object} entry What to show, e.g. {type, message}.
   */
  const storeNoticeAndReload = (key, entry) => {
    try {
      sessionStorage.setItem(key, JSON.stringify(entry));
    }
    catch (error) {
      // Without storage the notice is lost with the reload, but the page rebuilds correctly.
      console.error('H5P network admin:', error);
    }

    window.location.reload();
  };

  /**
   * Take the notice stored before the reload, once.
   *
   * @param {string} key Storage key of the page.
   *
   * @return {Object|null} The stored entry, or null.
   */
  const consumeStoredNotice = (key) => {
    let entry = null;
    try {
      const stored = sessionStorage.getItem(key);
      entry = stored ? JSON.parse(stored) : null;
    }
    catch {
      // sessionStorage unavailable (e.g. private mode), or a broken entry.
    }
    try {
      // Removed even if reading failed, so a stale entry cannot resurface later.
      sessionStorage.removeItem(key);
    }
    catch {
      // Nothing to do; the entry, if any, stays harmlessly stored.
    }
    return entry;
  };

  window.H5PNetworkCommon = {mount, showNotice, requestError, postAjax, storeNoticeAndReload, consumeStoredNotice};
})();
