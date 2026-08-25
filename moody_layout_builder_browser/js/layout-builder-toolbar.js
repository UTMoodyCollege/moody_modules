(function (Drupal, once, $) {
  const unsavedMessage = Drupal.t('You have unsaved changes.');
  const blockPreviewPreferenceKey =
    'Drupal.moodyLayoutBuilder.blockLivePreview';
  const mobilePreviewQuery = window.matchMedia('(max-width: 39.999rem)');
  const floatingPreviewQuery = window.matchMedia('(min-width: 64rem)');
  const blockPreviewDevices = {
    mobile: { width: 390, height: 844, label: Drupal.t('Mobile') },
    tablet: { width: 768, height: 1024, label: Drupal.t('Tablet') },
    desktop: { width: 1440, height: 900, label: Drupal.t('Desktop') },
  };

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

  const enhanceBlockEditControls = (context) => {
    once(
      'moody-layout-builder-block-edit-control',
      '.layout-builder-block > .contextual > .trigger',
      context,
    ).forEach((trigger) => {
      const editLink = trigger.parentElement?.querySelector(
        '.contextual-links a[href*="/layout_builder/update/block/"]',
      );
      if (!editLink) {
        return;
      }

      const title = trigger
        .closest('.layout-builder-block')
        ?.querySelector('h2')
        ?.textContent.trim();
      const accessibleLabel = title
        ? Drupal.t('@title block options', { '@title': title })
        : Drupal.t('Block options');

      trigger.classList.remove('visually-hidden', 'focusable');
      trigger.classList.add('moody-layout-builder-block__edit-trigger');
      trigger.dataset.moodyEditLabel = Drupal.t('Edit');
      trigger.dataset.moodyCloseLabel = Drupal.t('Close');
      trigger.setAttribute('aria-label', accessibleLabel);
    });
  };

  window.addEventListener('contextual-instances-added', () => {
    enhanceBlockEditControls(document);
  });

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

  const buildBlockPreviewDocument = (markup) => {
    const previewDocument = document.implementation.createHTMLDocument('');
    const base = previewDocument.createElement('base');
    const viewport = previewDocument.createElement('meta');
    const guard = previewDocument.createElement('style');

    previewDocument.documentElement.lang =
      document.documentElement.lang || 'en';
    previewDocument.documentElement.className =
      document.documentElement.className;
    previewDocument.body.className = document.body.className;
    base.href = document.baseURI;
    viewport.name = 'viewport';
    viewport.content = 'width=device-width, initial-scale=1';
    guard.textContent =
      'html, body { min-width: 0 !important; } body { margin: 0 !important; }';
    previewDocument.head.append(base, viewport);
    document.head
      .querySelectorAll('link[rel="stylesheet"], style')
      .forEach((asset) => {
        previewDocument.head.append(asset.cloneNode(true));
      });
    previewDocument.head.append(guard);
    previewDocument.body.innerHTML = markup;
    previewDocument.body.querySelectorAll('script').forEach((script) => {
      script.remove();
    });

    return `<!doctype html>${previewDocument.documentElement.outerHTML}`;
  };

  const enhanceBlockPreviewDevices = (context) => {
    const selector = '[data-moody-block-preview-device-controls]';
    const controls = [
      ...(context instanceof Element && context.matches(selector)
        ? [context]
        : []),
      ...(context.querySelectorAll?.(selector) || []),
    ];

    once('moody-block-preview-devices', controls).forEach((control) => {
      const protectedPreview = control.closest(
        '.moody-block-live-preview__protected',
      );
      const preview = control.closest('[data-moody-block-live-preview]');
      const form = preview?.closest(
        'form.layout-builder-add-block, form.layout-builder-configure-block',
      );
      const source = protectedPreview?.querySelector(
        '.moody-block-live-preview__viewport',
      );
      const buttons = [
        ...control.querySelectorAll('[data-moody-block-preview-device]'),
      ];
      if (!preview || !form || !source || buttons.length === 0) {
        return;
      }

      const stage = document.createElement('div');
      const canvas = document.createElement('div');
      const frame = document.createElement('iframe');
      const resizeObserver = new ResizeObserver(() => updateScale());
      const sourceDocument = buildBlockPreviewDocument(source.innerHTML);
      let selected = blockPreviewDevices[
        form.dataset.moodyBlockPreviewSelectedDevice
      ]
        ? form.dataset.moodyBlockPreviewSelectedDevice
        : 'desktop';

      stage.className = 'moody-block-live-preview__device-stage';
      canvas.className = 'moody-block-live-preview__device-canvas';
      frame.className = 'moody-block-live-preview__device-frame';
      frame.setAttribute('sandbox', '');
      frame.setAttribute('tabindex', '-1');
      frame.setAttribute('aria-hidden', 'true');
      frame.srcdoc = sourceDocument;
      canvas.append(frame);
      stage.append(canvas);
      source.replaceChildren(stage);

      function updateScale() {
        const device = blockPreviewDevices[selected];
        const scale = Math.min(1, stage.clientWidth / device.width || 1);
        canvas.style.setProperty(
          '--moody-block-preview-display-width',
          `${device.width * scale}px`,
        );
        canvas.style.setProperty(
          '--moody-block-preview-display-height',
          `${device.height * scale}px`,
        );
        frame.style.setProperty(
          '--moody-block-preview-device-width',
          `${device.width}px`,
        );
        frame.style.setProperty(
          '--moody-block-preview-device-height',
          `${device.height}px`,
        );
        frame.style.setProperty('--moody-block-preview-device-scale', scale);
      }

      const selectDevice = (deviceName, announce = false) => {
        const device = blockPreviewDevices[deviceName];
        if (!device) {
          return;
        }
        selected = deviceName;
        form.dataset.moodyBlockPreviewSelectedDevice = deviceName;
        buttons.forEach((button) => {
          const active = button.dataset.moodyBlockPreviewDevice === deviceName;
          button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        frame.title = Drupal.t(
          '@device block preview, @width by @height pixels',
          {
            '@device': device.label,
            '@width': device.width,
            '@height': device.height,
          },
        );
        updateScale();
        preview.moodyBlockPreviewPanel?.syncPosition();
        if (announce) {
          Drupal.announce(
            Drupal.t('@device preview selected.', {
              '@device': device.label,
            }),
          );
        }
      };

      const onClick = (event) => {
        selectDevice(event.currentTarget.dataset.moodyBlockPreviewDevice, true);
      };
      const onKeydown = (event) => {
        const current = buttons.indexOf(event.currentTarget);
        const destinations = {
          ArrowLeft: (current - 1 + buttons.length) % buttons.length,
          ArrowRight: (current + 1) % buttons.length,
          Home: 0,
          End: buttons.length - 1,
        };
        if (destinations[event.key] === undefined) {
          return;
        }
        event.preventDefault();
        buttons[destinations[event.key]].focus();
        buttons[destinations[event.key]].click();
      };

      buttons.forEach((button) => {
        const device = blockPreviewDevices[
          button.dataset.moodyBlockPreviewDevice
        ];
        button.setAttribute(
          'aria-label',
          Drupal.t('@device preview, @width by @height pixels', {
            '@device': device.label,
            '@width': device.width,
            '@height': device.height,
          }),
        );
        button.addEventListener('click', onClick);
        button.addEventListener('keydown', onKeydown);
      });
      resizeObserver.observe(stage);
      selectDevice(selected);
      window.requestAnimationFrame(updateScale);
      control.moodyBlockPreviewDevices = {
        destroy() {
          resizeObserver.disconnect();
          buttons.forEach((button) => {
            button.removeEventListener('click', onClick);
            button.removeEventListener('keydown', onKeydown);
          });
        },
      };
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

  const enhanceBlockFormToolbar = (context) => {
    once(
      'moody-block-form-toolbar',
      '[data-moody-block-form-toolbar]',
      context,
    ).forEach((toolbar) => {
      const form = toolbar.closest(
        'form.layout-builder-add-block, form.layout-builder-configure-block',
      );
      const status = toolbar.querySelector(
        '[data-moody-block-form-unsaved-status]',
      );
      if (!form || !status) {
        return;
      }

      const update = () => {
        const dirty = form.dataset.moodyBlockFormDirty === 'true';
        toolbar.classList.toggle('is-unsaved', dirty);
        status.hidden = !dirty;
        form.style.setProperty(
          '--moody-block-form-toolbar-height',
          `${Math.ceil(toolbar.getBoundingClientRect().height)}px`,
        );
      };
      const markDirty = (event) => {
        if (event.target.matches('[data-moody-block-preview-preference]')) {
          return;
        }
        form.dataset.moodyBlockFormDirty = 'true';
        update();
      };
      const resizeObserver = new ResizeObserver(update);

      $(form).on('formUpdated.moodyBlockFormToolbar', markDirty);
      resizeObserver.observe(toolbar);
      toolbar.moodyBlockFormToolbar = {
        form,
        markDirty,
        resizeObserver,
      };
      update();
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

  const syncMobileToolbar = (toolbar) => {
    const toggle = toolbar.querySelector(
      '[data-moody-layout-builder-toolbar-toggle]',
    );
    if (!toggle) {
      return;
    }

    const mobile = mobilePreviewQuery.matches;
    const collapsed =
      mobile && toolbar.dataset.moodyLayoutBuilderMobileCollapsed === 'true';

    toggle.hidden = !mobile;
    toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    toggle.setAttribute(
      'aria-label',
      collapsed
        ? Drupal.t('Show layout controls')
        : Drupal.t('Hide layout controls'),
    );
    toolbar.classList.toggle('is-mobile-collapsed', collapsed);
  };

  Drupal.behaviors.moodyLayoutBuilderToolbar = {
    attach(context) {
      enhanceEditorActions(context);
      enhanceBlockEditControls(context);
      enhanceBlockPreviewPanel(context);
      syncBlockPreviewResponse(context);
      enhanceBlockPreviewDevices(context);
      enhanceBlockLivePreview(context);
      enhanceBlockFormToolbar(context);

      const toolbar = document.querySelector(
        '[data-moody-layout-builder-toolbar]',
      );
      if (!toolbar) {
        return;
      }

      once('moody-layout-builder-toolbar', toolbar).forEach(() => {
        const toggle = toolbar.querySelector(
          '[data-moody-layout-builder-toolbar-toggle]',
        );
        if (toolbar.dataset.moodyLayoutBuilderMobileCollapsed === undefined) {
          toolbar.dataset.moodyLayoutBuilderMobileCollapsed = 'true';
        }

        const update = () => updateToolbar(toolbar);
        const sync = () => {
          syncMobileToolbar(toolbar);
          update();
        };
        const onToggle = () => {
          toolbar.dataset.moodyLayoutBuilderMobileCollapsed =
            toolbar.dataset.moodyLayoutBuilderMobileCollapsed === 'true'
              ? 'false'
              : 'true';
          sync();
        };
        const resizeObserver = new ResizeObserver(update);

        toolbar.moodyLayoutBuilderToolbarObservers = {
          resizeObserver,
          toggle,
          onToggle,
          sync,
        };
        toggle?.addEventListener('click', onToggle);
        mobilePreviewQuery.addEventListener('change', sync);
        resizeObserver.observe(toolbar);
        syncMobileToolbar(toolbar);
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

      once.remove(
        'moody-layout-builder-block-edit-control',
        '.layout-builder-block > .contextual > .trigger',
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
          'moody-block-preview-devices',
          '[data-moody-block-preview-device-controls]',
          context,
        )
        .forEach((control) => {
          control.moodyBlockPreviewDevices?.destroy();
          delete control.moodyBlockPreviewDevices;
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
          'moody-block-form-toolbar',
          '[data-moody-block-form-toolbar]',
          context,
        )
        .forEach((toolbar) => {
          const behavior = toolbar.moodyBlockFormToolbar;
          if (behavior) {
            $(behavior.form).off(
              'formUpdated.moodyBlockFormToolbar',
              behavior.markDirty,
            );
          }
          behavior?.resizeObserver.disconnect();
          delete toolbar.moodyBlockFormToolbar;
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
          observers?.toggle?.removeEventListener('click', observers.onToggle);
          if (observers?.sync) {
            mobilePreviewQuery.removeEventListener('change', observers.sync);
          }
          delete toolbar.moodyLayoutBuilderToolbarObservers;
        });
    },
  };
})(Drupal, once, jQuery);
