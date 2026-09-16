/* global H5PEditor H5PIntegration H5PHubManagement */
/**
 * Mounts H5P Hub client on network management page.
 *
 * Hub sits in iframe, as H5PEditor.Editor does it, so editor and hub stylesheets stay scoped and do
 * not restyle surrounding WordPress admin page. Only hub selector is built, not full editor, since
 * this page browses, installs and updates content types rather than creating content.
 */
((ns) => {
  const mount = () => {
    const container = document.querySelector('.h5p-hub-management');
    if (!container) {
      return;
    }

    if (!H5PIntegration.hubIsEnabled) {
      container.textContent =
        'The H5P Hub is disabled. Enable it in the H5P settings to install content types.';
      return;
    }

    const iframe = document.createElement('iframe');
    iframe.className = 'h5p-editor-iframe';
    iframe.setAttribute('frameBorder', '0');
    Object.assign(iframe.style, {
      display: 'block',
      width: '100%',
      height: '3em',
      border: 'none',
      zIndex: 101,
      top: 0,
      left: 0
    });

    /**
     * Write iframe document, pulling in editor assets.
     *
     * Inside frame, h5peditor.js picks up H5PEditor and H5PIntegration from window.parent.
     */
    const populateIframe = () => {
      if (!iframe.contentDocument) {
        return; // Not possible, iframe 'load' hasn't been triggered yet
      }

      // Management stylesheet comes last, so it overrides editor ones.
      // typeof guard, not optional chaining: global is absent if print_settings emitted nothing.
      const management = typeof H5PHubManagement !== 'undefined' ? H5PHubManagement : {};

      const styles = [...ns.assets.css, ...(management.style ? [management.style] : [])];

      iframe.contentDocument.open();
      iframe.contentDocument.write(
        `<!doctype html><html lang="${ns.contentLanguage}">` +
        '<head>' +
        ns.wrap('<link rel="stylesheet" href="', styles, '">') +
        ns.wrap('<script src="', ns.assets.js, '"></script>') +
        '</head><body>' +
        '<div class="h5p-hub-management-container h5peditor"></div>' +
        '</body></html>'
      );
      iframe.contentDocument.close();
      iframe.contentDocument.documentElement.style.overflow = 'hidden';
    };

    /**
     * Grow iframe to fit its content.
     */
    const resize = () => {
      if (!iframe.contentDocument?.body) {
        return;
      }

      iframe.style.height = `${iframe.contentDocument.body.scrollHeight}px`;
    };

    iframe.addEventListener('load', async () => {
      const innerWindow = iframe.contentWindow;

      if (!innerWindow.H5P) {
        // Iframe was reloaded and lost its content.
        setTimeout(populateIframe, 0);
        return;
      }

      const innerNs = innerWindow.H5PEditor;
      const target = iframe.contentDocument.querySelector('body > .h5p-hub-management-container');

      // Must stay jQuery: H5P core calls get(), add() and unbind() on H5P.$body.
      innerWindow.H5P.$body = innerWindow.H5P.jQuery(iframe.contentDocument.body);

      let data;
      try {
        const response = await fetch(innerNs.getAjaxUrl('content-type-cache'), {
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin'
        });
        if (!response.ok) {
          throw new Error(`Unexpected status ${response.status}`);
        }

        data = await response.json();
      }
      catch (error) {
        console.error('H5P hub management:', error);
        target.textContent = 'Error, unable to load content types.';
        resize();
        return;
      }

      if (data.success === false) {
        target.textContent = `${data.message} (${data.errorCode})`;
        resize();
        return;
      }

      // Hub client reads translations from H5PEditor.language.core when constructed, so override first.
      const labels = typeof H5PHubManagement !== 'undefined' ? H5PHubManagement : {};
      if (innerNs.language?.core) {
        // || not ??, so empty translations keep hub's own label.
        innerNs.language.core.hubPanelLabel =
          labels.hubPanelLabel || innerNs.language.core.hubPanelLabel;
      }

      // No selected library, no change dialog: selector never enters content creation.
      const selector = new innerNs.SelectorHub(data, '', undefined);

      target.replaceChildren(selector.getElement());

      selector.on('resize', resize);
      resize();

      // Hub also resizes without announcing it, as panels open and installs finish.
      if (innerWindow.ResizeObserver) {
        new innerWindow.ResizeObserver(resize)
          .observe(iframe.contentDocument.body);
      }
    });

    container.replaceChildren(iframe);
    populateIframe();
  };

  // Script is enqueued while page body renders, so DOMContentLoaded may already have fired.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  }
  else {
    mount();
  }
})(H5PEditor);
