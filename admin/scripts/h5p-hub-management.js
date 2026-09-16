/* global H5P H5PEditor H5PIntegration H5PHubManagement */
/**
 * Mounts the H5P Hub client on the network management page.
 *
 * The hub is put inside an iframe, the way H5PEditor.Editor does it, so the
 * editor and hub stylesheets stay scoped to it and do not restyle the
 * surrounding WordPress admin page. Only the hub selector is created, not a
 * full editor: creating content is not offered here, and browsing, installing
 * and updating content types is all the hub itself needs.
 */
(function ($, ns) {
  $(document).ready(function () {
    var $container = $('.h5p-hub-management');
    if (!$container.length) {
      return;
    }

    if (!H5PIntegration.hubIsEnabled) {
      $container.text(
        'The H5P Hub is disabled. Enable it in the H5P settings to install content types.'
      );
      return;
    }

    var $iframe = $('<iframe/>', {
      'css': {
        display: 'block',
        width: '100%',
        height: '3em',
        border: 'none',
        zIndex: 101,
        top: 0,
        left: 0
      },
      'class': 'h5p-editor-iframe',
      'frameBorder': '0'
    });

    var iframe = $iframe.get(0);

    /**
     * Write the iframe document, pulling in the editor assets.
     *
     * H5PIntegration.editor.assets is exactly what the editor loads inside its
     * own iframe. Inside the frame h5peditor.js picks up H5PEditor and
     * H5PIntegration from window.parent, which is this page.
     */
    var populateIframe = function () {
      if (!iframe.contentDocument) {
        return; // Not possible, iframe 'load' hasn't been triggered yet
      }

      // The management stylesheet comes last, so it overrides the editor ones.
      var styles = ns.assets.css.concat(
        typeof H5PHubManagement !== 'undefined' && H5PHubManagement.style
          ? [H5PHubManagement.style]
          : []
      );

      iframe.contentDocument.open();
      iframe.contentDocument.write(
        '<!doctype html><html lang="' + ns.contentLanguage + '">' +
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
     * Grow the iframe to fit its content.
     */
    var resize = function () {
      if (!iframe.contentDocument || !iframe.contentDocument.body) {
        return;
      }

      iframe.style.height = iframe.contentDocument.body.scrollHeight + 'px';
    };

    $iframe.on('load', function () {
      var innerWindow = iframe.contentWindow;

      if (!innerWindow.H5P) {
        // The iframe has been reloaded and lost its content.
        setTimeout(populateIframe, 0);
        return;
      }

      var innerNs = innerWindow.H5PEditor;
      var $inner = innerWindow.H5P.jQuery;
      var $target = $inner('body > .h5p-hub-management-container');

      innerWindow.H5P.$body = $inner(iframe.contentDocument.body);

      $inner.ajax({
        url: innerNs.getAjaxUrl('content-type-cache')
      }).fail(function () {
        $target.text('Error, unable to load content types.');
        resize();
      }).done(function (data) {
        if (data.success === false) {
          $target.text(data.message + ' (' + data.errorCode + ')');
          resize();
          return;
        }

        // The hub client takes its translations from H5PEditor.language.core
        // when it is constructed, so the panel label is overridden here. This
        // page manages content types rather than selecting one to author with.
        if (innerNs.language && innerNs.language.core) {
          innerNs.language.core.hubPanelLabel =
            H5PHubManagement.hubPanelLabel || innerNs.language.core.hubPanelLabel;
        }

        // No selected library and no change library dialog: the selector stays
        // in browse, install and update mode and never enters content creation.
        var selector = new innerNs.SelectorHub(data, '', undefined);

        $target.empty().append(selector.getElement());

        selector.on('resize', resize);
        resize();

        // The hub also grows and shrinks without announcing it, as panels open,
        // content types are filtered and installs finish.
        if (innerWindow.ResizeObserver) {
          new innerWindow.ResizeObserver(resize)
            .observe(iframe.contentDocument.body);
        }
      });
    });

    $container.empty().append($iframe);
    populateIframe();
  });
})(H5P.jQuery, H5PEditor);
