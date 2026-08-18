(function (Drupal, once) {
  const unsavedMessage = Drupal.t('You have unsaved changes.');

  const hasUnsavedChanges = () =>
    document.getElementById('layout-builder')?.dataset
      .moodyLayoutBuilderUnsaved === 'true';

  const hideRedundantCoreMessage = () => {
    document.querySelectorAll('[role="contentinfo"]').forEach((message) => {
      const heading = message.querySelector('h2');
      const messageText = message.textContent
        .replace(heading?.textContent || '', '')
        .trim();
      if (messageText === unsavedMessage) {
        message.hidden = true;
      }
    });
  };

  const enhanceEditorActions = (context) => {
    once(
      'moody-layout-editor-actions',
      '.block-local-tasks-block ul.tabs.primary',
      context,
    ).forEach((tabs) => {
      const heading = tabs.previousElementSibling;
      if (!heading || heading.tagName !== 'H2') {
        return;
      }

      heading.classList.remove('visually-hidden');
      heading.classList.add('moody-layout-editor-actions__title');
      heading.textContent = Drupal.t('Editor actions');
    });
  };

  const updateToolbar = (toolbar) => {
    const status = toolbar.querySelector(
      '[data-layout-builder-unsaved-status]',
    );
    const dirty = hasUnsavedChanges();

    toolbar.classList.toggle('is-unsaved', dirty);
    if (status) {
      status.hidden = !dirty;
    }
    hideRedundantCoreMessage();

    document.documentElement.style.setProperty(
      '--moody-layout-builder-toolbar-height',
      `${Math.ceil(toolbar.getBoundingClientRect().height)}px`,
    );
  };

  Drupal.behaviors.moodyLayoutBuilderToolbar = {
    attach(context) {
      enhanceEditorActions(context);

      const toolbar = document.querySelector(
        '[data-moody-layout-builder-toolbar]',
      );
      if (!toolbar) {
        return;
      }

      once('moody-layout-builder-toolbar', toolbar).forEach(() => {
        const update = () => updateToolbar(toolbar);
        const resizeObserver = new ResizeObserver(update);

        toolbar.moodyLayoutBuilderToolbarObservers = {
          resizeObserver,
        };
        resizeObserver.observe(toolbar);
      });
      updateToolbar(toolbar);
    },

    detach(context, settings, trigger) {
      if (trigger !== 'unload') {
        return;
      }

      once.remove(
        'moody-layout-editor-actions',
        '.block-local-tasks-block ul.tabs.primary',
        context,
      );

      once
        .remove(
          'moody-layout-builder-toolbar',
          '[data-moody-layout-builder-toolbar]',
          context,
        )
        .forEach((toolbar) => {
          const observers = toolbar.moodyLayoutBuilderToolbarObservers;
          observers?.resizeObserver.disconnect();
          delete toolbar.moodyLayoutBuilderToolbarObservers;
        });
    },
  };
})(Drupal, once);
