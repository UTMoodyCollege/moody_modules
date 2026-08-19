(function (Drupal, once) {
  const unsavedMessage = Drupal.t('You have unsaved changes.');
  const blockPreviewPreferenceKey =
    'Drupal.moodyLayoutBuilder.blockLivePreview';
  const mobilePreviewQuery = window.matchMedia('(max-width: 39.999rem)');
  const floatingPreviewQuery = window.matchMedia('(min-width: 64rem)');

  const blockPreviewIsEnabled = () => {
    try {
      return window.localStorage.getItem(blockPreviewPreferenceKey) !== 'false';
    } catch (error) {
      return true;
    }
  };

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

  const syncBlockPreviewPanel = (preview) => {
    const form = preview.closest(
      'form.layout-builder-add-block, form.layout-builder-configure-block',
    );
    const toggle = preview.querySelector('[data-moody-block-preview-toggle]');
    const body = preview.querySelector('.moody-block-live-preview__body');
    const collapsed = form?.dataset.moodyBlockPreviewCollapsed === 'true';
    const enabled = form?.dataset.moodyBlockPreviewEnabled !== 'false';

    preview.hidden = !enabled;
    preview.classList.toggle('is-collapsed', collapsed);
    if (body) {
      body.hidden = collapsed;
    }
    if (toggle) {
      const label = collapsed
        ? Drupal.t('Expand preview')
        : Drupal.t('Collapse preview');
      toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      toggle.setAttribute('aria-label', label);
      toggle.textContent = label;
      if (mobilePreviewQuery.matches) {
        toggle.textContent = collapsed
          ? Drupal.t('Open preview')
          : Drupal.t('Collapse');
      }
    }
  };

  const getBlockPreviewPosition = (form) => ({
    x: Number.parseFloat(form.dataset.moodyBlockPreviewX) || 0,
    y: Number.parseFloat(form.dataset.moodyBlockPreviewY) || 0,
  });

  const setBlockPreviewPosition = (preview, form, x, y) => {
    if (!floatingPreviewQuery.matches || preview.hidden) {
      return;
    }

    const bounds = preview.getBoundingClientRect();
    const appliedX = Number.parseFloat(
      preview.style.getPropertyValue('--moody-block-preview-x'),
    ) || 0;
    const appliedY = Number.parseFloat(
      preview.style.getPropertyValue('--moody-block-preview-y'),
    ) || 0;
    const baseX = bounds.left - appliedX;
    const baseY = bounds.top - appliedY;
    const headerHeight =
      preview
        .querySelector('.moody-block-live-preview__header')
        ?.getBoundingClientRect().height || 64;
    const gutter = 8;
    const minX = gutter - baseX;
    const minY = gutter - baseY;
    const maxX = Math.max(
      minX,
      window.innerWidth - gutter - bounds.width - baseX,
    );
    const maxY = Math.max(
      minY,
      window.innerHeight - gutter - headerHeight - baseY,
    );
    const nextX = Math.min(maxX, Math.max(minX, x));
    const nextY = Math.min(maxY, Math.max(minY, y));
    const availableHeight = Math.max(
      headerHeight,
      window.innerHeight - gutter - baseY - nextY,
    );

    form.dataset.moodyBlockPreviewX = String(nextX);
    form.dataset.moodyBlockPreviewY = String(nextY);
    preview.style.setProperty('--moody-block-preview-x', `${nextX}px`);
    preview.style.setProperty('--moody-block-preview-y', `${nextY}px`);
    preview.style.setProperty(
      '--moody-block-preview-available-height',
      `${availableHeight}px`,
    );
  };

  const enhanceBlockPreviewPanel = (context) => {
    const selector = '[data-moody-block-live-preview]';
    const previews = [
      ...(context instanceof Element && context.matches(selector)
        ? [context]
        : []),
      ...(context.querySelectorAll?.(selector) || []),
    ];

    once('moody-block-live-preview-panel', previews).forEach((preview) => {
      const toggle = preview.querySelector('[data-moody-block-preview-toggle]');
      const move = preview.querySelector('[data-moody-block-preview-move]');
      const refresh = preview.querySelector('[data-moody-block-preview-refresh]');
      const updated = preview.querySelector('[data-moody-block-preview-updated]');
      const form = preview.closest(
        'form.layout-builder-add-block, form.layout-builder-configure-block',
      );
      if (!toggle || !form) {
        return;
      }

      if (form.dataset.moodyBlockPreviewCollapsed === undefined) {
        form.dataset.moodyBlockPreviewCollapsed = mobilePreviewQuery.matches
          ? 'true'
          : 'false';
      }

      let dragState;
      let flashFrame;
      let flashTimer;
      let positionFrame;
      let positionAnnouncementTimer;

      const onToggle = () => {
        form.dataset.moodyBlockPreviewCollapseSetByUser = 'true';
        form.dataset.moodyBlockPreviewCollapsed =
          form.dataset.moodyBlockPreviewCollapsed === 'true'
            ? 'false'
            : 'true';
        syncBlockPreviewPanel(preview);
      };

      const queuePositionSync = () => {
        window.cancelAnimationFrame(positionFrame);
        positionFrame = window.requestAnimationFrame(() => {
          const position = getBlockPreviewPosition(form);
          setBlockPreviewPosition(preview, form, position.x, position.y);
        });
      };

      const onMobileChange = () => {
        if (form.dataset.moodyBlockPreviewCollapseSetByUser !== 'true') {
          form.dataset.moodyBlockPreviewCollapsed = mobilePreviewQuery.matches
            ? 'true'
            : 'false';
          syncBlockPreviewPanel(preview);
        }
      };

      const markPreviewPending = () => {
        form.dataset.moodyBlockPreviewPending = 'true';
      };

      const finishDrag = (event) => {
        if (!dragState || event.pointerId !== dragState.pointerId) {
          return;
        }
        dragState = undefined;
        preview.classList.remove('is-dragging');
        if (move?.hasPointerCapture(event.pointerId)) {
          move.releasePointerCapture(event.pointerId);
        }
        Drupal.announce(Drupal.t('Preview moved.'));
      };

      const onPointerDown = (event) => {
        if (!floatingPreviewQuery.matches || event.button !== 0) {
          return;
        }
        event.preventDefault();
        move.focus({ preventScroll: true });
        dragState = {
          pointerId: event.pointerId,
          startClientX: event.clientX,
          startClientY: event.clientY,
          ...getBlockPreviewPosition(form),
        };
        move.setPointerCapture(event.pointerId);
        preview.classList.add('is-dragging');
        Drupal.announce(Drupal.t('Moving preview.'));
      };

      const onPointerMove = (event) => {
        if (!dragState || event.pointerId !== dragState.pointerId) {
          return;
        }
        event.preventDefault();
        setBlockPreviewPosition(
          preview,
          form,
          dragState.x + event.clientX - dragState.startClientX,
          dragState.y + event.clientY - dragState.startClientY,
        );
      };

      const onMoveKeydown = (event) => {
        const directions = {
          ArrowDown: [0, 1],
          ArrowLeft: [-1, 0],
          ArrowRight: [1, 0],
          ArrowUp: [0, -1],
        };
        if (!floatingPreviewQuery.matches || !directions[event.key]) {
          return;
        }
        event.preventDefault();
        const position = getBlockPreviewPosition(form);
        const distance = event.shiftKey ? 48 : 16;
        const [x, y] = directions[event.key];
        setBlockPreviewPosition(
          preview,
          form,
          position.x + x * distance,
          position.y + y * distance,
        );
        window.clearTimeout(positionAnnouncementTimer);
        positionAnnouncementTimer = window.setTimeout(() => {
          Drupal.announce(Drupal.t('Preview moved.'));
        }, 250);
      };

      const showUpdated = () => {
        if (!updated) {
          return;
        }
        updated.hidden = false;
        window.cancelAnimationFrame(flashFrame);
        flashFrame = window.requestAnimationFrame(() => {
          updated.classList.add('is-visible');
        });
        window.clearTimeout(flashTimer);
        flashTimer = window.setTimeout(() => {
          updated.classList.remove('is-visible');
          updated.hidden = true;
        }, 2200);
      };

      toggle.addEventListener('click', onToggle);
      refresh?.addEventListener('pointerdown', markPreviewPending);
      refresh?.addEventListener('click', markPreviewPending);
      move?.addEventListener('pointerdown', onPointerDown);
      move?.addEventListener('lostpointercapture', finishDrag);
      move?.addEventListener('keydown', onMoveKeydown);
      mobilePreviewQuery.addEventListener('change', onMobileChange);
      floatingPreviewQuery.addEventListener('change', queuePositionSync);
      window.addEventListener('pointermove', onPointerMove);
      window.addEventListener('pointerup', finishDrag);
      window.addEventListener('pointercancel', finishDrag);
      window.addEventListener('resize', queuePositionSync);
      preview.moodyBlockPreviewPanel = {
        showUpdated,
        syncPosition: queuePositionSync,
        destroy() {
          window.cancelAnimationFrame(flashFrame);
          window.cancelAnimationFrame(positionFrame);
          window.clearTimeout(flashTimer);
          window.clearTimeout(positionAnnouncementTimer);
          toggle.removeEventListener('click', onToggle);
          refresh?.removeEventListener('pointerdown', markPreviewPending);
          refresh?.removeEventListener('click', markPreviewPending);
          move?.removeEventListener('pointerdown', onPointerDown);
          move?.removeEventListener('lostpointercapture', finishDrag);
          move?.removeEventListener('keydown', onMoveKeydown);
          mobilePreviewQuery.removeEventListener('change', onMobileChange);
          floatingPreviewQuery.removeEventListener(
            'change',
            queuePositionSync,
          );
          window.removeEventListener('pointermove', onPointerMove);
          window.removeEventListener('pointerup', finishDrag);
          window.removeEventListener('pointercancel', finishDrag);
          window.removeEventListener('resize', queuePositionSync);
        },
      };
      syncBlockPreviewPanel(preview);
      queuePositionSync();
      if (form.dataset.moodyBlockPreviewPending === 'true') {
        form.dataset.moodyBlockPreviewPending = 'false';
        if (preview.dataset.state === 'ready') {
          showUpdated();
        }
      }
    });
  };

  const syncBlockPreviewResponse = (context) => {
    const selector = '[data-moody-block-preview-body]';
    const bodies = [
      ...(context instanceof Element && context.matches(selector)
        ? [context]
        : []),
      ...(context.querySelectorAll?.(selector) || []),
    ];

    bodies.forEach((body) => {
      const preview = body.closest('[data-moody-block-live-preview]');
      const form = preview?.closest(
        'form.layout-builder-add-block, form.layout-builder-configure-block',
      );
      if (!preview || !form) {
        return;
      }

      preview.dataset.state = body.dataset.state;
      preview.removeAttribute('aria-busy');
      syncBlockPreviewPanel(preview);
      preview.moodyBlockPreviewPanel?.syncPosition();
      if (form.dataset.moodyBlockPreviewPending === 'true') {
        form.dataset.moodyBlockPreviewPending = 'false';
        if (preview.dataset.state === 'ready') {
          preview.moodyBlockPreviewPanel?.showUpdated();
        }
      }
    });
  };

  const syncBlockPreviewStickyOffset = (form) => {
    const preview = form.querySelector('[data-moody-block-live-preview]');
    if (!preview) {
      return;
    }

    let offset = 0;
    form
      .querySelectorAll('.ck-sticky-panel__content_sticky')
      .forEach((panel) => {
        const bounds = panel.getBoundingClientRect();
        if (bounds.width > 0 && bounds.height > 0 && bounds.bottom > 0) {
          offset = Math.max(offset, Math.ceil(bounds.bottom));
        }
      });
    preview.style.setProperty(
      '--moody-block-preview-sticky-top',
      `${offset}px`,
    );
  };

  const enhanceBlockLivePreview = (context) => {
    once(
      'moody-block-live-preview',
      'form.layout-builder-add-block, form.layout-builder-configure-block',
      context,
    ).forEach((form) => {
      let refreshTimer;
      let stickyFrame;
      const preview = form.querySelector('[data-moody-block-live-preview]');
      if (!preview) {
        return;
      }

      const preference = document.createElement('label');
      const preferenceCheckbox = document.createElement('input');
      const preferenceLabel = document.createElement('span');
      preference.className = 'moody-block-live-preview__preference';
      preferenceCheckbox.type = 'checkbox';
      preferenceCheckbox.checked = blockPreviewIsEnabled();
      preferenceCheckbox.setAttribute(
        'data-moody-block-preview-preference',
        '',
      );
      preferenceLabel.textContent = Drupal.t('Block live preview');
      preference.append(preferenceCheckbox, preferenceLabel);
      preview.before(preference);

      const queueStickySync = () => {
        window.cancelAnimationFrame(stickyFrame);
        stickyFrame = window.requestAnimationFrame(() => {
          syncBlockPreviewStickyOffset(form);
        });
      };
      const stickyObserver = new MutationObserver(queueStickySync);
      const syncPreference = () => {
        const enabled = preferenceCheckbox.checked;
        form.dataset.moodyBlockPreviewEnabled = enabled ? 'true' : 'false';
        const currentPreview = form.querySelector(
          '[data-moody-block-live-preview]',
        );
        if (currentPreview) {
          syncBlockPreviewPanel(currentPreview);
        }
        if (!enabled) {
          window.clearTimeout(refreshTimer);
        } else {
          queueStickySync();
        }
      };
      const onPreferenceChange = () => {
        try {
          window.localStorage.setItem(
            blockPreviewPreferenceKey,
            preferenceCheckbox.checked ? 'true' : 'false',
          );
        } catch (error) {
          // The switch still works for the current form when storage is blocked.
        }
        syncPreference();
        if (preferenceCheckbox.checked) {
          refresh();
        }
        Drupal.announce(
          preferenceCheckbox.checked
            ? Drupal.t('Block live preview enabled.')
            : Drupal.t('Block live preview disabled.'),
        );
      };
      const refresh = () => {
        if (form.dataset.moodyBlockPreviewEnabled === 'false') {
          return;
        }
        const button = form.querySelector(
          '[data-moody-block-preview-refresh]',
        );
        const ajax = Drupal.ajax?.instances?.find(
          (instance) => instance?.element === button,
        );
        if (!button || !ajax) {
          return;
        }
        if (button.disabled || ajax.ajaxing) {
          refreshTimer = window.setTimeout(refresh, 200);
          return;
        }
        form.dataset.moodyBlockPreviewPending = 'true';
        button.dispatchEvent(
          new MouseEvent(ajax.elementSettings.event, {
            bubbles: true,
            cancelable: true,
            view: window,
          }),
        );
      };
      const queueRefresh = (event) => {
        const target = event.target;
        if (
          form.dataset.moodyBlockPreviewEnabled === 'false' ||
          !(target instanceof Element) ||
          target.matches('[data-moody-block-preview-preference]') ||
          target.closest('[data-moody-block-live-preview]') ||
          (event.type === 'input' &&
            target.matches('[data-autocomplete-path]')) ||
          (event.type === 'focusout' &&
            !target.matches('[data-autocomplete-path]')) ||
          target.matches(
            'button, input[type="button"], input[type="file"], input[type="submit"]',
          )
        ) {
          return;
        }
        const preview = form.querySelector('[data-moody-block-live-preview]');
        const status = form.querySelector('[data-moody-block-preview-status]');
        if (preview) {
          preview.dataset.state = 'updating';
          preview.setAttribute('aria-busy', 'true');
        }
        if (status) {
          status.textContent = Drupal.t('Updating preview…');
        }
        window.clearTimeout(refreshTimer);
        refreshTimer = window.setTimeout(refresh, 600);
      };

      const onEscape = (event) => {
        if (
          event.key !== 'Escape' ||
          event.defaultPrevented ||
          !floatingPreviewQuery.matches
        ) {
          return;
        }
        const close = window.frameElement
          ?.closest('.ui-dialog')
          ?.querySelector('.ui-dialog-titlebar-close');
        if (close) {
          event.preventDefault();
          close.click();
        }
      };

      preferenceCheckbox.addEventListener('change', onPreferenceChange);
      form.addEventListener('input', queueRefresh, true);
      form.addEventListener('change', queueRefresh, true);
      form.addEventListener('focusout', queueRefresh, true);
      document.addEventListener('keydown', onEscape);
      window.addEventListener('resize', queueStickySync);
      stickyObserver.observe(form, {
        attributes: true,
        attributeFilter: ['class'],
        childList: true,
        subtree: true,
      });
      syncPreference();
      queueStickySync();
      form.moodyBlockLivePreview = {
        destroy() {
          window.clearTimeout(refreshTimer);
          window.cancelAnimationFrame(stickyFrame);
          preferenceCheckbox.removeEventListener(
            'change',
            onPreferenceChange,
          );
          form.removeEventListener('input', queueRefresh, true);
          form.removeEventListener('change', queueRefresh, true);
          form.removeEventListener('focusout', queueRefresh, true);
          document.removeEventListener('keydown', onEscape);
          window.removeEventListener('resize', queueStickySync);
          stickyObserver.disconnect();
        },
      };
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
      enhanceBlockPreviewPanel(context);
      syncBlockPreviewResponse(context);
      enhanceBlockLivePreview(context);

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
          'moody-block-live-preview-panel',
          '[data-moody-block-live-preview]',
          context,
        )
        .forEach((preview) => {
          preview.moodyBlockPreviewPanel?.destroy();
          delete preview.moodyBlockPreviewPanel;
        });

      once
        .remove(
          'moody-block-live-preview',
          'form.layout-builder-add-block, form.layout-builder-configure-block',
          context,
        )
        .forEach((form) => {
          form.moodyBlockLivePreview?.destroy();
          delete form.moodyBlockLivePreview;
        });

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
