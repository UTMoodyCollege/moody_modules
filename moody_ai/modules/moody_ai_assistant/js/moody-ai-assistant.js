(function (Drupal, drupalSettings, once) {
  const uploaderDebugPrefix = '[ai-blocks uploader]';
  const assistantAssetMarker = 'ai-blocks-chat-assistant asset loaded 2026-07-14';
  const assistantStatePrefix = 'aiBlocksAssistant:';
  const assistantPreferencePrefix = 'aiBlocksAssistantPrefs:';
  const desktopMediaQuery = '(min-width: 48rem)';
  const panelWidthMin = 360;
  const panelWidthMax = 1120;
  const historyHeightMin = 200;
  const formHeightMin = 208;
  const streamInactivityWarningMs = 120000;
  const thinkingStatusByWrapper = new WeakMap();

  console.log(assistantAssetMarker, {
    path: 'web/modules/custom/moody_ai_assistant/js/ai-blocks-chat-assistant.js'
  });

  function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
  }

  function getAssistantStorageKey(wrapper) {
    return wrapper.dataset.aiAssistantKey || '';
  }

  function getOpenStateStorageKey(wrapper) {
    const key = getAssistantStorageKey(wrapper);
    return key ? assistantStatePrefix + key : '';
  }

  function getPreferenceStorageKey(wrapper) {
    const key = getAssistantStorageKey(wrapper);
    return key ? assistantPreferencePrefix + key : '';
  }

  function readLocalStorage(key) {
    if (!key) {
      return null;
    }

    try {
      return window.localStorage.getItem(key);
    }
    catch (error) {
      return null;
    }
  }

  function writeLocalStorage(key, value) {
    if (!key) {
      return;
    }

    try {
      window.localStorage.setItem(key, value);
    }
    catch (error) {
      // Ignore storage failures and keep the UI functional.
    }
  }

  function readAssistantPreferences(wrapper) {
    const rawValue = readLocalStorage(getPreferenceStorageKey(wrapper));
    if (!rawValue) {
      return {};
    }

    try {
      const parsed = JSON.parse(rawValue);
      return parsed && typeof parsed === 'object' ? parsed : {};
    }
    catch (error) {
      return {};
    }
  }

  function writeAssistantPreferences(wrapper, updates) {
    const current = readAssistantPreferences(wrapper);
    const next = Object.assign({}, current, updates);
    writeLocalStorage(getPreferenceStorageKey(wrapper), JSON.stringify(next));
    return next;
  }

  function applyAssistantWidth(wrapper, width) {
    if (!Number.isFinite(width) || width <= 0) {
      wrapper.style.removeProperty('--ai-moody-assistant-panel-width');
      return null;
    }

    const maxWidth = Math.min(panelWidthMax, window.innerWidth - 32);
    const nextWidth = clamp(width, panelWidthMin, maxWidth);
    wrapper.style.setProperty('--ai-moody-assistant-panel-width', nextWidth + 'px');
    return nextWidth;
  }

  function getHistoryHeightBounds(wrapper) {
    const panel = wrapper.querySelector('.ai-moody-assistant__panel');
    const header = wrapper.querySelector('.ai-moody-assistant__header');
    const conversations = wrapper.querySelector('.ai-moody-assistant__conversations');
    const historyResizer = wrapper.querySelector('[data-ai-assistant-history-resizer]');

    if (!panel) {
      return null;
    }

    const reservedHeight = (header ? header.offsetHeight : 0)
      + (conversations ? conversations.offsetHeight : 0)
      + (historyResizer ? historyResizer.offsetHeight : 0);
    const maxHistoryHeight = Math.max(historyHeightMin, panel.clientHeight - reservedHeight - formHeightMin);

    return {
      min: historyHeightMin,
      max: maxHistoryHeight
    };
  }

  function applyHistoryHeight(wrapper, preferredHeight) {
    const bounds = getHistoryHeightBounds(wrapper);
    if (!bounds) {
      return null;
    }

    const history = wrapper.querySelector('.ai-moody-assistant__history');
    const fallbackHeight = history ? history.offsetHeight : historyHeightMin;
    const sourceHeight = Number.isFinite(preferredHeight) ? preferredHeight : fallbackHeight;
    const nextHeight = clamp(sourceHeight, bounds.min, bounds.max);

    wrapper.style.setProperty('--ai-moody-assistant-history-height', nextHeight + 'px');
    return nextHeight;
  }

  function applyAssistantPreferences(wrapper) {
    const preferences = readAssistantPreferences(wrapper);
    const isDesktop = window.matchMedia(desktopMediaQuery).matches;

    if (isDesktop) {
      if (Number.isFinite(preferences.width)) {
        applyAssistantWidth(wrapper, preferences.width);
      }

      if (Number.isFinite(preferences.historyHeight)) {
        applyHistoryHeight(wrapper, preferences.historyHeight);
      }
    }
    else {
      wrapper.style.removeProperty('--ai-moody-assistant-panel-width');
      wrapper.style.removeProperty('--ai-moody-assistant-history-height');
    }
  }

  function bindPanelResizeControls(wrapper) {
    const panel = wrapper.querySelector('.ai-moody-assistant__panel');
    const widthHandle = wrapper.querySelector('[data-ai-assistant-width-resizer]');
    const historyHandle = wrapper.querySelector('[data-ai-assistant-history-resizer]');
    const history = wrapper.querySelector('.ai-moody-assistant__history');
    const conversationSwitcher = wrapper.querySelector('.ai-moody-assistant__conversation-switcher');

    if (!panel || !widthHandle || !historyHandle || !history) {
      return;
    }

    const beginResize = (handle, onMove, onEnd) => {
      handle.addEventListener('pointerdown', (event) => {
        if (event.button !== 0) {
          return;
        }

        event.preventDefault();
        wrapper.classList.add('is-resizing');
        handle.setPointerCapture(event.pointerId);

        const finish = () => {
          wrapper.classList.remove('is-resizing');
          onEnd();
        };

        const handlePointerMove = (moveEvent) => {
          onMove(moveEvent);
        };

        const handlePointerUp = () => {
          handle.removeEventListener('pointermove', handlePointerMove);
          handle.removeEventListener('pointerup', handlePointerUp);
          handle.removeEventListener('pointercancel', handlePointerUp);
          finish();
        };

        handle.addEventListener('pointermove', handlePointerMove);
        handle.addEventListener('pointerup', handlePointerUp);
        handle.addEventListener('pointercancel', handlePointerUp);
      });
    };

    beginResize(
      widthHandle,
      (event) => {
        if (!window.matchMedia(desktopMediaQuery).matches) {
          return;
        }

        const panelRect = panel.getBoundingClientRect();
        applyAssistantWidth(wrapper, panelRect.right - event.clientX);
      },
      () => {
        if (!window.matchMedia(desktopMediaQuery).matches) {
          return;
        }

        writeAssistantPreferences(wrapper, { width: wrapper.getBoundingClientRect().width });
      }
    );

    beginResize(
      historyHandle,
      (() => {
        let startY = 0;
        let startHeight = 0;

        historyHandle.addEventListener('pointerdown', (event) => {
          startY = event.clientY;
          startHeight = history.offsetHeight;
        });

        return (event) => {
          applyHistoryHeight(wrapper, startHeight + (event.clientY - startY));
        };
      })(),
      () => {
        writeAssistantPreferences(wrapper, { historyHeight: history.offsetHeight });
      }
    );

    widthHandle.addEventListener('keydown', (event) => {
      if (!window.matchMedia(desktopMediaQuery).matches) {
        return;
      }

      const currentWidth = wrapper.getBoundingClientRect().width;
      let nextWidth = currentWidth;

      if (event.key === 'ArrowLeft') {
        nextWidth += 24;
      }
      else if (event.key === 'ArrowRight') {
        nextWidth -= 24;
      }
      else if (event.key === 'Home') {
        nextWidth = panelWidthMin;
      }
      else if (event.key === 'End') {
        nextWidth = Math.min(panelWidthMax, window.innerWidth - 32);
      }
      else {
        return;
      }

      event.preventDefault();
      const appliedWidth = applyAssistantWidth(wrapper, nextWidth);
      if (appliedWidth) {
        writeAssistantPreferences(wrapper, { width: appliedWidth });
      }
    });

    historyHandle.addEventListener('keydown', (event) => {
      const currentHeight = history.offsetHeight;
      let nextHeight = currentHeight;

      if (event.key === 'ArrowUp') {
        nextHeight -= 24;
      }
      else if (event.key === 'ArrowDown') {
        nextHeight += 24;
      }
      else if (event.key === 'Home') {
        nextHeight = historyHeightMin;
      }
      else if (event.key === 'End') {
        const bounds = getHistoryHeightBounds(wrapper);
        nextHeight = bounds ? bounds.max : currentHeight;
      }
      else {
        return;
      }

      event.preventDefault();
      const appliedHeight = applyHistoryHeight(wrapper, nextHeight);
      if (appliedHeight) {
        writeAssistantPreferences(wrapper, { historyHeight: appliedHeight });
      }
    });

    if (conversationSwitcher) {
      conversationSwitcher.addEventListener('toggle', () => {
        window.requestAnimationFrame(() => {
          applyAssistantPreferences(wrapper);
        });
      });
    }

    window.addEventListener('resize', () => {
      applyAssistantPreferences(wrapper);
    });

    window.requestAnimationFrame(() => {
      applyAssistantPreferences(wrapper);
    });
  }

  function scrollHistoryToBottom(wrapper) {
    const history = wrapper.querySelector('.ai-moody-assistant__history');
    if (!history) {
      return;
    }

    window.requestAnimationFrame(() => {
      history.scrollTop = history.scrollHeight;
    });
  }

  function bindConversationSearch(wrapper) {
    const search = wrapper.querySelector('.ai-moody-assistant__conversation-search');
    if (!search) {
      return;
    }

    const cards = Array.from(wrapper.querySelectorAll('.ai-moody-assistant__conversation-card'));
    const filterCards = () => {
      const query = search.value.trim().toLowerCase();
      cards.forEach((card) => {
        const haystack = (card.dataset.conversationText || '').toLowerCase();
        card.hidden = query !== '' && !haystack.includes(query);
      });
    };

    search.addEventListener('input', filterCards);
  }

  function getComposerElements(wrapper) {
    return {
      source: wrapper.querySelector('[data-ai-assistant-composer-source]'),
      editor: wrapper.querySelector('[data-ai-assistant-composer-editor]'),
      shell: wrapper.querySelector('[data-ai-assistant-composer-shell]')
    };
  }

  function getComposerText(wrapper) {
    const composer = getComposerElements(wrapper);
    if (!composer.editor) {
      return composer.source ? composer.source.value : '';
    }

    return composer.editor.textContent.replace(/\u00a0/g, ' ').replace(/\s+$/u, '');
  }

  function focusComposer(wrapper) {
    const composer = getComposerElements(wrapper);
    if (!composer.editor) {
      return;
    }

    composer.editor.focus();
    const selection = window.getSelection();
    if (!selection) {
      return;
    }

    const range = document.createRange();
    range.selectNodeContents(composer.editor);
    range.collapse(false);
    selection.removeAllRanges();
    selection.addRange(range);
  }

  function scrollComposerIntoView(wrapper) {
    const formPane = wrapper.querySelector('.ai-moody-assistant__form');
    const composer = getComposerElements(wrapper);
    if (!formPane || !composer.shell) {
      return;
    }

    window.requestAnimationFrame(() => {
      formPane.scrollTo({
        top: Math.max(0, composer.shell.offsetTop - 24),
        behavior: 'smooth'
      });
    });
  }

  function setComposerText(wrapper, nextText) {
    const composer = getComposerElements(wrapper);
    if (!composer.source) {
      return;
    }

    const normalized = String(nextText || '');
    composer.source.value = normalized;
    if (composer.editor && composer.editor.textContent !== normalized) {
      composer.editor.textContent = normalized;
    }
  }

  function insertComposerText(wrapper, addition) {
    const existing = getComposerText(wrapper).trim();
    const nextText = existing ? existing + '\n\n' + addition : addition;
    setComposerText(wrapper, nextText);
    focusComposer(wrapper);
  }

  function estimateTokenCount(value) {
    const normalized = String(value || '').trim();
    if (normalized === '') {
      return 0;
    }

    return Math.max(1, Math.ceil(normalized.length / 4));
  }

  function summarizeFilesForTokens(files) {
    if (!Array.isArray(files) || !files.length) {
      return '';
    }

    return files.map((file) => {
      const parts = [file.name || 'file'];
      if (file.type) {
        parts.push(file.type);
      }
      if (Number.isFinite(file.size)) {
        parts.push(String(file.size));
      }
      return parts.join(' ');
    }).join('\n');
  }

  function bindTokenCounter(wrapper) {
    const toggle = wrapper.querySelector('[data-ai-assistant-token-counter-toggle]');
    const popover = wrapper.querySelector('[data-ai-assistant-token-counter-popover]');
    const close = wrapper.querySelector('[data-ai-assistant-token-counter-close]');
    const summaryLine = wrapper.querySelector('[data-ai-assistant-token-counter-summary]');
    const conversationLine = wrapper.querySelector('[data-ai-assistant-token-counter-conversation]');
    const requestLine = wrapper.querySelector('[data-ai-assistant-token-counter-request]');

    if (!toggle || !popover || !close || !summaryLine || !conversationLine || !requestLine) {
      return {
        update() {
        }
      };
    }

    const setOpen = (isOpen) => {
      popover.hidden = !isOpen;
      toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    };

    toggle.addEventListener('click', (event) => {
      event.preventDefault();
      setOpen(popover.hidden);
    });

    close.addEventListener('click', (event) => {
      event.preventDefault();
      setOpen(false);
      toggle.focus();
    });

    document.addEventListener('click', (event) => {
      if (!(event.target instanceof Element)) {
        return;
      }

      if (!wrapper.contains(event.target)) {
        setOpen(false);
        return;
      }

      if (!event.target.closest('[data-ai-assistant-token-counter]')) {
        setOpen(false);
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !popover.hidden) {
        event.stopImmediatePropagation();
        setOpen(false);
        toggle.focus();
      }
    });

    const update = (state) => {
      const nextState = state || {};
      const history = wrapper.querySelector('.ai-moody-assistant__history');
      const conversationText = history ? history.innerText.replace(/\s+/g, ' ').trim() : '';
      const draftText = String(nextState.message || getComposerText(wrapper) || '').trim();
      const selectedBlocks = Array.isArray(nextState.selectedBlocks) ? nextState.selectedBlocks : [];
      const selectedText = selectedBlocks.map((item) => [item.label || '', item.typeLabel || '', item.selectionMode || ''].join(' ')).join('\n');
      const fileText = summarizeFilesForTokens(Array.isArray(nextState.files) ? nextState.files : []);
      const preferenceText = nextState.preferAiImages ? 'Generate AI Images enabled' : '';
      const requestPayloadText = [draftText, selectedText, fileText, preferenceText].filter(Boolean).join('\n\n');
      const conversationTokens = estimateTokenCount(conversationText);
      const requestTokens = estimateTokenCount(requestPayloadText);
      const combinedTokens = conversationTokens + requestTokens;

      toggle.textContent = '~' + combinedTokens + ' tokens';
      summaryLine.textContent = 'Combined: ~' + combinedTokens + ' tokens';
      conversationLine.textContent = 'Conversation context: ~' + conversationTokens + ' tokens currently in view';
      requestLine.textContent = 'Next send: ~' + requestTokens + ' tokens in this draft, ~' + combinedTokens + ' combined';
    };

    setOpen(false);
    update();

    return {
      update
    };
  }

  function bindTokenizedComposer(wrapper) {
    const composer = getComposerElements(wrapper);
    if (!composer.source || !composer.editor || !composer.shell) {
      return null;
    }

    composer.shell.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target || !target.closest('.ai-moody-assistant__selected-block-chip-remove')) {
        focusComposer(wrapper);
      }
    });

    composer.editor.addEventListener('input', () => {
      composer.source.value = composer.editor.textContent.replace(/\u00a0/g, ' ');
    });

    composer.editor.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && !event.shiftKey && !event.metaKey && !event.ctrlKey) {
        event.preventDefault();
        document.execCommand('insertLineBreak');
      }
    });

    setComposerText(wrapper, composer.source.value);

    return composer;
  }

  function bindStarterPrompts(wrapper) {
    const promptButtons = Array.from(wrapper.querySelectorAll('[data-ai-assistant-prompt]'));
    if (!promptButtons.length) {
      return;
    }

    promptButtons.forEach((button) => {
      button.addEventListener('click', (event) => {
        event.preventDefault();
        const prompt = button.getAttribute('data-ai-assistant-prompt') || '';
        if (prompt === '') {
          return;
        }

        insertComposerText(wrapper, prompt);
      });
    });
  }

  function formatElapsedTime(milliseconds) {
    const totalSeconds = Math.max(0, Math.floor(milliseconds / 1000));
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    if (minutes > 0) {
      return minutes + 'm ' + String(seconds).padStart(2, '0') + 's';
    }

    return totalSeconds + 's';
  }

  function formatLastActivity(milliseconds) {
    const totalSeconds = Math.max(0, Math.floor(milliseconds / 1000));
    if (totalSeconds <= 1) {
      return 'just now';
    }

    return totalSeconds + 's ago';
  }

  function ensureThinkingStatus(wrapper, messageElement) {
    const existingState = thinkingStatusByWrapper.get(wrapper);
    if (existingState && existingState.messageElement === messageElement) {
      return existingState;
    }

    const meta = messageElement.querySelector('[data-ai-assistant-thinking-meta]');
    if (!meta) {
      return null;
    }

    if (existingState && existingState.intervalId) {
      window.clearInterval(existingState.intervalId);
    }

    const state = {
      messageElement,
      metaElement: meta,
      startedAt: Date.now(),
      lastActivityAt: Date.now(),
      updateCount: 0,
      intervalId: 0,
      isError: false
    };

    const render = () => {
      const now = Date.now();
      const elapsed = formatElapsedTime(now - state.startedAt);
      const lastActivity = formatLastActivity(now - state.lastActivityAt);
      const label = state.isError ? 'Last live update' : 'Live activity';
      const updates = state.updateCount > 0 ? state.updateCount + ' update' + (state.updateCount === 1 ? '' : 's') : 'waiting for first update';
      state.metaElement.textContent = 'Elapsed ' + elapsed + ' | ' + label + ' ' + lastActivity + ' | ' + updates;
      state.metaElement.classList.toggle('is-stale', !state.isError && (now - state.lastActivityAt) >= 10000);
    };

    state.render = render;
    state.intervalId = window.setInterval(render, 1000);
    render();
    thinkingStatusByWrapper.set(wrapper, state);
    return state;
  }

  function stopThinkingStatus(wrapper, isError) {
    const state = thinkingStatusByWrapper.get(wrapper);
    if (!state) {
      return;
    }

    state.isError = Boolean(isError);
    if (state.intervalId) {
      window.clearInterval(state.intervalId);
      state.intervalId = 0;
    }

    if (typeof state.render === 'function') {
      state.render();
    }
  }

  function appendThinkingMessage(wrapper) {
    const history = wrapper.querySelector('.ai-moody-assistant__history');
    if (!history) {
      return;
    }

    const existing = history.querySelector('.ai-moody-assistant__message--thinking');
    if (existing) {
      return;
    }

    const message = document.createElement('div');
    message.className = 'ai-moody-assistant__message ai-moody-assistant__message--assistant ai-moody-assistant__message--thinking';
    message.setAttribute('aria-live', 'polite');
    message.innerHTML = '<strong>Moody</strong><div data-ai-assistant-thinking-body="true">Thinking<span class="ai-moody-assistant__thinking-dots" aria-hidden="true"><span>.</span><span>.</span><span>.</span></span></div><div class="ai-moody-assistant__thinking-meta" data-ai-assistant-thinking-meta="true"></div>';
    history.appendChild(message);
    ensureThinkingStatus(wrapper, message);
    scrollHistoryToBottom(wrapper);
  }

  function clearRetryAction(messageElement) {
    if (!messageElement) {
      return;
    }

    const existing = messageElement.querySelector('[data-ai-assistant-retry-action]');
    if (existing) {
      existing.remove();
    }
  }

  function appendRetryAction(messageElement, onRetry) {
    if (!messageElement || typeof onRetry !== 'function') {
      return;
    }

    clearRetryAction(messageElement);

    const actions = document.createElement('div');
    actions.className = 'ai-moody-assistant__preview-actions';
    actions.setAttribute('data-ai-assistant-retry-action', 'true');

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'ai-moody-assistant__preview-button ai-moody-assistant__preview-button--approve';
    button.textContent = 'Retry';
    button.addEventListener('click', (event) => {
      event.preventDefault();
      onRetry();
    });

    actions.appendChild(button);
    messageElement.appendChild(actions);
  }

  function appendUserMessage(wrapper, content) {
    const history = wrapper.querySelector('.ai-moody-assistant__history');
    if (!history) {
      return null;
    }

    const message = document.createElement('div');
    message.className = 'ai-moody-assistant__message ai-moody-assistant__message--user';
    message.innerHTML = '<strong>You</strong><div></div>';
    message.querySelector('div').textContent = content;
    history.appendChild(message);
    scrollHistoryToBottom(wrapper);
    return message;
  }

  function appendSelectedBlockPreview(messageElement, selectedBlocks) {
    if (!messageElement || !Array.isArray(selectedBlocks) || !selectedBlocks.length) {
      return;
    }

    const preview = document.createElement('div');
    preview.className = 'ai-moody-assistant__preview';

    const title = document.createElement('div');
    title.className = 'ai-moody-assistant__preview-title';
    title.textContent = 'Selected components';
    preview.appendChild(title);

    const list = document.createElement('div');
    list.className = 'ai-moody-assistant__selected-block-preview-list';

    selectedBlocks.forEach((blockRef) => {
      const chip = document.createElement('span');
      chip.className = 'ai-moody-assistant__selected-block-preview-chip ai-moody-assistant__selected-block-preview-chip--' + (blockRef.selectionMode === 'edit' ? 'edit' : 'new');
      chip.textContent = blockRef.label || 'Selected block';
      if (blockRef.typeLabel) {
        const meta = document.createElement('small');
        meta.textContent = blockRef.typeLabel;
        chip.appendChild(meta);
      }
      list.appendChild(chip);
    });

    preview.appendChild(list);
    messageElement.appendChild(preview);
  }

  function appendPreferencePreview(messageElement, preferAiImages) {
    if (!messageElement || !preferAiImages) {
      return;
    }

    const preview = document.createElement('div');
    preview.className = 'ai-moody-assistant__preview';

    const title = document.createElement('div');
    title.className = 'ai-moody-assistant__preview-title';
    title.textContent = 'Image preference';
    preview.appendChild(title);

    const badge = document.createElement('span');
    badge.className = 'ai-moody-assistant__preference-badge';
    badge.textContent = 'AI image';
    preview.appendChild(badge);

    messageElement.appendChild(preview);
  }

  function bindImageGenerationPreference(wrapper) {
    const toggle = wrapper.querySelector('[data-ai-assistant-prefer-ai-images-toggle]');
    const input = wrapper.querySelector('[data-ai-assistant-prefer-ai-images-input]');

    if (!toggle || !input) {
      return {
        isPreferred() {
          return false;
        },
        clear() {
        }
      };
    }

    const sync = (isPreferred) => {
      input.value = isPreferred ? '1' : '0';
      toggle.checked = isPreferred;
      const wrapperElement = toggle.closest('.ai-moody-assistant__image-preference-toggle');
      if (wrapperElement) {
        wrapperElement.classList.toggle('is-active', isPreferred);
      }
    };

    sync(input.value === '1');

    toggle.addEventListener('change', () => {
      sync(toggle.checked);
      focusComposer(wrapper);
    });

    return {
      isPreferred() {
        return input.value === '1';
      },
      clear() {
        sync(false);
      }
    };
  }

  function bindBlockReferencePicker(wrapper) {
    const composer = getComposerElements(wrapper);
    const input = composer.editor;
    const shell = composer.shell;
    const hiddenInput = wrapper.querySelector('[data-ai-assistant-selected-block-input]');
    const selectedContainer = wrapper.querySelector('[data-ai-assistant-selected-blocks]');
    const selectedList = wrapper.querySelector('[data-ai-assistant-selected-block-list]');
    const blockLibraries = Array.from(wrapper.querySelectorAll('[data-ai-assistant-block-picker]'));
    const blockButtons = Array.from(wrapper.querySelectorAll('[data-ai-assistant-block-ref]'));

    if (!input || !shell || !hiddenInput || !selectedContainer || !selectedList || !blockLibraries.length || !blockButtons.length) {
      return {
        getSelected() {
          return [];
        },
        clear() {
        }
      };
    }

    const selected = [];
    const selectedById = new Map();

    const hydrateReference = (button) => ({
      referenceId: button.getAttribute('data-ai-assistant-block-ref-reference-id') || button.getAttribute('data-ai-assistant-block-ref-plugin-id') || button.getAttribute('data-ai-assistant-block-ref-id') || '',
      uuid: button.getAttribute('data-ai-assistant-block-ref-id') || '',
      label: button.getAttribute('data-ai-assistant-block-ref-label') || 'Selected block',
      typeLabel: button.getAttribute('data-ai-assistant-block-ref-type') || 'Block',
      pluginId: button.getAttribute('data-ai-assistant-block-ref-plugin-id') || '',
      blockType: button.getAttribute('data-ai-assistant-block-ref-block-type') || '',
      selectionMode: button.getAttribute('data-ai-assistant-block-ref-mode') === 'edit' ? 'edit' : 'new',
      groupLabel: button.getAttribute('data-ai-assistant-block-ref-group-label') || 'Available blocks',
      existingCount: Number.parseInt(button.getAttribute('data-ai-assistant-block-ref-existing-count') || '0', 10),
      canEdit: button.getAttribute('data-ai-assistant-block-ref-can-edit') === 'true'
    });

    const syncHiddenInput = () => {
      hiddenInput.value = JSON.stringify(selected.map((item) => ({
        reference_id: item.referenceId,
        uuid: item.uuid,
        label: item.label,
        type_label: item.typeLabel,
        plugin_id: item.pluginId,
        block_type: item.blockType,
        selection_mode: item.selectionMode,
        group_label: item.groupLabel,
        existing_count: item.existingCount,
        can_edit: item.canEdit
      })));
    };

    const renderSelected = () => {
      selectedList.innerHTML = '';
      selectedContainer.classList.toggle('has-selected-blocks', selected.length > 0);

      selected.forEach((item, index) => {
        const chip = document.createElement('div');
        chip.className = 'ai-moody-assistant__selected-block-chip ai-moody-assistant__selected-block-chip--' + item.selectionMode;

        const title = document.createElement('span');
        title.className = 'ai-moody-assistant__selected-block-chip-title';
        title.textContent = item.label;

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'ai-moody-assistant__selected-block-chip-remove';
        remove.setAttribute('aria-label', 'Remove ' + item.label + ' from chat');
        remove.textContent = 'x';
        remove.addEventListener('click', () => {
          selected.splice(index, 1);
          selectedById.delete(item.referenceId);
          renderSelected();
        });

        chip.appendChild(title);
        chip.appendChild(remove);
        selectedList.appendChild(chip);
      });

      syncHiddenInput();
    };

    const addReference = (reference) => {
      if (!reference || !reference.referenceId || selectedById.has(reference.referenceId)) {
        return;
      }

      console.log(assistantAssetMarker, 'component token added', {
        referenceId: reference.referenceId,
        label: reference.label,
        blockType: reference.blockType,
        pluginId: reference.pluginId
      });

      selected.push(reference);
      selectedById.set(reference.referenceId, reference);
      renderSelected();

      if (getComposerText(wrapper).trim() === '') {
        setComposerText(wrapper, reference.selectionMode === 'edit' ? 'Update the selected existing block.' : 'Use the selected block type as the pattern for this request.');
      }

      scrollComposerIntoView(wrapper);
      scrollHistoryToBottom(wrapper);
      focusComposer(wrapper);
    };

    blockLibraries.forEach((blockLibrary) => {
      blockLibrary.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const button = target ? target.closest('[data-ai-assistant-block-ref]') : null;
        if (!button) {
          return;
        }

        event.preventDefault();
        addReference(hydrateReference(button));
      });

      blockLibrary.addEventListener('keydown', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const button = target ? target.closest('[data-ai-assistant-block-ref]') : null;
        if (!button) {
          return;
        }

        if (event.key !== 'Enter' && event.key !== ' ') {
          return;
        }

        event.preventDefault();
        addReference(hydrateReference(button));
      });
    });

    return {
      getSelected() {
        return selected.slice();
      },
      clear() {
        selected.length = 0;
        selectedById.clear();
        renderSelected();
      }
    };
  }

  function updateThinkingMessage(wrapper, content, isError, onRetry) {
    const history = wrapper.querySelector('.ai-moody-assistant__history');
    if (!history) {
      return;
    }

    let message = history.querySelector('.ai-moody-assistant__message--thinking');
    if (!message) {
      appendThinkingMessage(wrapper);
      message = history.querySelector('.ai-moody-assistant__message--thinking');
      if (!message) {
        return;
      }
    }

    message.classList.toggle('ai-moody-assistant__message--error', Boolean(isError));
    const body = message.querySelector('[data-ai-assistant-thinking-body]') || message.querySelector('div');
    if (body) {
      body.textContent = content;
    }
    const state = ensureThinkingStatus(wrapper, message);
    if (state) {
      state.lastActivityAt = Date.now();
      state.updateCount += 1;
      state.isError = Boolean(isError);
      state.render();
    }
    if (isError) {
      stopThinkingStatus(wrapper, true);
      appendRetryAction(message, onRetry);
    }
    else {
      clearRetryAction(message);
    }
    scrollHistoryToBottom(wrapper);
  }

  function createStreamWatchdog(wrapper, onTimeout) {
    let timeoutId = 0;
    let isActive = true;

    const clear = () => {
      if (timeoutId) {
        window.clearTimeout(timeoutId);
        timeoutId = 0;
      }
    };

    const schedule = () => {
      clear();
      if (!isActive) {
        return;
      }

      timeoutId = window.setTimeout(() => {
        if (!isActive) {
          return;
        }

        isActive = false;
        onTimeout();
      }, streamInactivityWarningMs);
    };

    schedule();

    return {
      markActivity() {
        if (!isActive) {
          return;
        }

        schedule();
      },
      stop() {
        isActive = false;
        clear();
      }
    };
  }

  function formatFileSize(bytes) {
    if (!Number.isFinite(bytes) || bytes <= 0) {
      return '';
    }

    if (bytes >= 1024 * 1024) {
      return (bytes / (1024 * 1024)).toFixed(1).replace(/\.0$/, '') + ' MB';
    }

    return Math.max(1, Math.round(bytes / 1024)) + ' KB';
  }

  function buildFileKey(file) {
    return [file.name, file.size, file.lastModified, file.type].join('::');
  }

  function getFileExtension(fileName) {
    const parts = String(fileName || '').split('.');
    return parts.length > 1 ? parts.pop().toUpperCase() : 'FILE';
  }

  function getFileBadgeLabel(file) {
    if (file.type && file.type.startsWith('image/')) {
      return 'Image';
    }

    return getFileExtension(file.name);
  }

  function renderSelectedFiles(fileList, files, onRemove) {
    if (!fileList) {
      return;
    }

    fileList.innerHTML = '';
    fileList.hidden = files.length === 0;

    files.forEach((file, index) => {
      const chip = document.createElement('div');
      chip.className = 'ai-moody-assistant__file-chip';

      const meta = document.createElement('div');
      meta.className = 'ai-moody-assistant__file-chip-meta';

      const badge = document.createElement('span');
      badge.className = 'ai-moody-assistant__file-chip-badge';
      badge.textContent = getFileBadgeLabel(file);

      const name = document.createElement('span');
      name.className = 'ai-moody-assistant__file-chip-name';
      name.textContent = file.name;

      const details = document.createElement('span');
      details.className = 'ai-moody-assistant__file-chip-size';
      details.textContent = formatFileSize(file.size);

      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'ai-moody-assistant__file-chip-remove';
      remove.setAttribute('aria-label', 'Remove ' + file.name);
      remove.textContent = 'Remove';
      remove.addEventListener('click', () => {
        onRemove(index);
      });

      meta.appendChild(badge);
      meta.appendChild(name);
      if (details.textContent) {
        meta.appendChild(details);
      }
      chip.appendChild(meta);
      chip.appendChild(remove);
      fileList.appendChild(chip);
    });
  }

  function bindFileUploader(wrapper) {
    const form = wrapper.querySelector('.ai-moody-assistant__form form');
    const fileInput = wrapper.querySelector('.ai-moody-assistant__composer-file-input');
    const dropzone = wrapper.querySelector('[data-ai-assistant-dropzone]');
    const fileList = wrapper.querySelector('[data-ai-assistant-file-list]');
    const primaryCopy = wrapper.querySelector('[data-ai-assistant-dropzone-primary]');
    const secondaryCopy = wrapper.querySelector('[data-ai-assistant-dropzone-secondary]');
    const hintCopy = wrapper.querySelector('[data-ai-assistant-dropzone-hint]');
    const countBadge = wrapper.querySelector('[data-ai-assistant-dropzone-count]');

    if (!form || !fileInput || !dropzone || !fileList) {
      console.log(uploaderDebugPrefix, 'uploader not initialized', {
        hasForm: Boolean(form),
        hasFileInput: Boolean(fileInput),
        hasDropzone: Boolean(dropzone),
        hasFileList: Boolean(fileList)
      });
      return null;
    }

    console.log(uploaderDebugPrefix, 'uploader initialized', {
      assistantKey: wrapper.dataset.aiAssistantKey || '',
      inputId: fileInput.id || ''
    });

    let selectedFiles = [];
    let isDragOver = false;
    const maxFiles = Number.parseInt(fileInput.dataset.maxFiles || '3', 10);
    const maxFileBytes = Number.parseInt(fileInput.dataset.maxFileBytes || '5242880', 10);
    const maxTotalBytes = Number.parseInt(fileInput.dataset.maxTotalBytes || '10485760', 10);
    const allowedExtensions = new Set(String(fileInput.accept || '').split(',').map(value => value.trim().replace(/^\./, '').toLowerCase()).filter(Boolean));

    const existingMediaCount = () => wrapper.querySelectorAll('input[name*="existing_media"][name$="[target_id]"]').length;

    const validateFiles = (files) => {
      if (files.length + existingMediaCount() > maxFiles) {
        throw new Error('Select no more than ' + maxFiles + ' total files and Media items.');
      }
      const oversized = files.find((file) => file.size > maxFileBytes);
      if (oversized) {
        throw new Error(oversized.name + ' exceeds the ' + formatFileSize(maxFileBytes) + ' file limit.');
      }
      if (files.reduce((total, file) => total + file.size, 0) > maxTotalBytes) {
        throw new Error('Attachments exceed the ' + formatFileSize(maxTotalBytes) + ' combined limit.');
      }
    };

    const updateDropzoneCopy = () => {
      if (!primaryCopy || !secondaryCopy || !hintCopy || !countBadge) {
        return;
      }

      const count = selectedFiles.length;
      countBadge.hidden = count === 0;
      countBadge.textContent = String(count);

      if (isDragOver) {
        primaryCopy.textContent = 'Drop to attach';
        secondaryCopy.textContent = 'Release now';
        hintCopy.textContent = 'You can also click to browse instead of dragging files in.';
        return;
      }

      if (count === 0) {
        primaryCopy.textContent = 'Add files';
        secondaryCopy.textContent = 'Drop or browse';
        hintCopy.textContent = 'PNG, JPG, GIF, PDF, DOC, DOCX, TXT, CSV up to 10 MB each';
        return;
      }

      if (count === 1) {
        primaryCopy.textContent = '1 file ready';
        secondaryCopy.textContent = 'Add more';
        hintCopy.textContent = 'Remove any file chip below if you want to change the selection.';
        return;
      }

      primaryCopy.textContent = count + ' files ready';
      secondaryCopy.textContent = 'Add more';
      hintCopy.textContent = 'Remove any file chip below if you want to change the selection.';
    };

    const refresh = () => {
      renderSelectedFiles(fileList, selectedFiles, (index) => {
        selectedFiles.splice(index, 1);
        refresh();
      });
      dropzone.classList.toggle('has-files', selectedFiles.length > 0);
      updateDropzoneCopy();
    };

    const addFiles = (incomingFiles) => {
      const nextFiles = Array.from(incomingFiles || []).filter(Boolean);
      if (!nextFiles.length) {
        console.log(uploaderDebugPrefix, 'no files received');
        return;
      }

      console.log(uploaderDebugPrefix, 'received files', nextFiles.map((file) => ({
        name: file.name,
        size: file.size,
        type: file.type
      })));

      const seen = new Set(selectedFiles.map((file) => buildFileKey(file)));
      const combined = selectedFiles.slice();
      nextFiles.forEach((file) => {
        const key = buildFileKey(file);
        if (!seen.has(key)) {
          combined.push(file);
          seen.add(key);
        }
      });
      validateFiles(combined);
      selectedFiles = combined;
      refresh();
    };

    dropzone.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        console.log(uploaderDebugPrefix, 'keyboard browse trigger', { key: event.key });
        event.preventDefault();
        fileInput.click();
      }
    });

    fileInput.addEventListener('change', () => {
      console.log(uploaderDebugPrefix, 'native file input changed', {
        count: fileInput.files ? fileInput.files.length : 0
      });
      try {
        addFiles(fileInput.files);
      }
      catch (error) {
        hintCopy.textContent = error.message;
        dropzone.classList.add('is-error');
      }
      fileInput.value = '';
    });

    [dropzone, fileInput].forEach((target) => {
      ['dragenter', 'dragover'].forEach((eventName) => {
        target.addEventListener(eventName, (event) => {
          console.log(uploaderDebugPrefix, 'drag event', { eventName: eventName });
          event.preventDefault();
          isDragOver = true;
          dropzone.classList.add('is-dragover');
          updateDropzoneCopy();
        });
      });
    });

    [dropzone, fileInput].forEach((target) => {
      ['dragleave', 'dragend', 'drop'].forEach((eventName) => {
        target.addEventListener(eventName, (event) => {
          console.log(uploaderDebugPrefix, 'drag end event', { eventName: eventName });
          event.preventDefault();
          if (eventName !== 'dragleave' || event.target === dropzone || event.target === fileInput || !dropzone.contains(event.relatedTarget)) {
            isDragOver = false;
            dropzone.classList.remove('is-dragover');
            updateDropzoneCopy();
          }
        });
      });
    });

    [dropzone, fileInput].forEach((target) => {
      target.addEventListener('drop', (event) => {
        console.log(uploaderDebugPrefix, 'drop received', {
          count: event.dataTransfer && event.dataTransfer.files ? event.dataTransfer.files.length : 0
        });
        event.preventDefault();
        try {
          addFiles(event.dataTransfer ? event.dataTransfer.files : []);
        }
        catch (error) {
          hintCopy.textContent = error.message;
          dropzone.classList.add('is-error');
        }
      });
    });

    wrapper.addEventListener('paste', (event) => {
      const images = Array.from((event.clipboardData && event.clipboardData.files) || []).filter(file => file.type.startsWith('image/'));
      if (!images.length) {
        return;
      }
      event.preventDefault();
      try {
        const extensionByType = {
          'image/gif': 'gif',
          'image/jpeg': 'jpg',
          'image/png': 'png',
          'image/webp': 'webp'
        };
        const now = Date.now();
        const pasted = images.map((file, index) => {
          const extension = extensionByType[file.type];
          if (!extension || !allowedExtensions.has(extension)) {
            throw new Error('Paste a PNG, GIF, JPEG, or WebP image.');
          }
          return new File([file], 'pasted-image-' + now + '-' + (index + 1) + '.' + extension, {
            type: file.type,
            lastModified: now
          });
        });
        addFiles(pasted);
        dropzone.classList.remove('is-error');
        hintCopy.textContent = pasted.length + ' clipboard image' + (pasted.length === 1 ? '' : 's') + ' ready.';
      }
      catch (error) {
        hintCopy.textContent = error.message;
        dropzone.classList.add('is-error');
      }
    });

    refresh();

    return {
      hasFiles() {
        return selectedFiles.length > 0;
      },
      getFiles() {
        return selectedFiles.slice();
      },
      validate() {
        validateFiles(selectedFiles);
      },
      clear() {
        selectedFiles = [];
        refresh();
      }
    };
  }

  async function consumeStream(response, wrapper, options) {
    const streamOptions = options || {};
    if (!response.body) {
      throw new Error('The browser did not provide a readable response stream.');
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    const handleEventBlock = (block) => {
      const lines = block.split('\n');
      let eventName = 'message';
      const dataLines = [];

      lines.forEach((line) => {
        if (line.startsWith('event:')) {
          eventName = line.slice(6).trim();
        }
        else if (line.startsWith('data:')) {
          dataLines.push(line.slice(5).trim());
        }
      });

      if (!dataLines.length) {
        return;
      }

      const payload = JSON.parse(dataLines.join('\n'));
      if (typeof streamOptions.onActivity === 'function') {
        streamOptions.onActivity(eventName, payload);
      }
      if (eventName === 'status') {
        updateThinkingMessage(wrapper, payload.message || 'Thinking...');
      }
      else if (eventName === 'complete') {
        updateThinkingMessage(wrapper, payload.status_message || 'Done.');
        stopThinkingStatus(wrapper, false);
        window.setTimeout(() => {
          window.location.href = payload.redirect_url || payload.reload_url || window.location.href;
        }, 250);
      }
      else if (eventName === 'error') {
        throw new Error(payload.message || 'The AI request failed.');
      }
    };

    while (true) {
      const { value, done } = await reader.read();
      if (done) {
        break;
      }

      buffer += decoder.decode(value, { stream: true });
      let separatorIndex = buffer.indexOf('\n\n');
      while (separatorIndex !== -1) {
        const block = buffer.slice(0, separatorIndex).trim();
        buffer = buffer.slice(separatorIndex + 2);
        if (block !== '') {
          handleEventBlock(block);
        }
        separatorIndex = buffer.indexOf('\n\n');
      }
    }
  }

  function bindFormSubmission(wrapper) {
    const form = wrapper.querySelector('.ai-moody-assistant__form form');
    const composer = bindTokenizedComposer(wrapper);
    const input = wrapper.querySelector('[data-ai-assistant-composer-source]');
    const submit = wrapper.querySelector('.ai-moody-assistant__composer-actions input[type="submit"], .ai-moody-assistant__composer-actions button[type="submit"]');
    const uploader = bindFileUploader(wrapper);
    const blockReferences = bindBlockReferencePicker(wrapper);
    const imagePreference = bindImageGenerationPreference(wrapper);
    const tokenCounter = bindTokenCounter(wrapper);
    if (!form || !input || !submit) {
      return;
    }

    const refreshTokenCounter = () => {
      tokenCounter.update({
        message: getComposerText(wrapper),
        selectedBlocks: blockReferences ? blockReferences.getSelected() : [],
        files: uploader ? uploader.getFiles() : [],
        preferAiImages: imagePreference ? imagePreference.isPreferred() : false
      });
    };

    let activeAbortController = null;

    const composerEditor = wrapper.querySelector('[data-ai-assistant-composer-editor]');
    if (composerEditor) {
      composerEditor.addEventListener('input', refreshTokenCounter);
    }

    const nativeInput = wrapper.querySelector('[data-ai-assistant-composer-source]');
    if (nativeInput) {
      nativeInput.addEventListener('input', refreshTokenCounter);
    }

    wrapper.addEventListener('click', () => {
      window.requestAnimationFrame(refreshTokenCounter);
    });

    wrapper.addEventListener('change', () => {
      window.requestAnimationFrame(refreshTokenCounter);
    });

    refreshTokenCounter();

    let isSubmitting = false;

    const submitRequest = async () => {
      if (isSubmitting) {
        return;
      }

      console.log(uploaderDebugPrefix, 'form submit intercepted', {
        hasUploader: Boolean(uploader),
        messageLength: getComposerText(wrapper).trim().length
      });
      if (getComposerText(wrapper).trim() === '') {
        return;
      }

      try {
        if (uploader) {
          uploader.validate();
        }
      }
      catch (error) {
        const shell = wrapper.querySelector('[data-ai-assistant-composer-shell]');
        if (shell) {
          shell.classList.add('is-error');
        }
        return;
      }

      const streamSettings = drupalSettings.moodyAiAssistant || {};
      if (!streamSettings.streamUrl || !streamSettings.csrfToken) {
        return;
      }

      isSubmitting = true;
      const message = getComposerText(wrapper).trim();
      input.value = message;
      setOpenState(wrapper, true);
      const userMessageElement = appendUserMessage(wrapper, message);
      if (blockReferences) {
        appendSelectedBlockPreview(userMessageElement, blockReferences.getSelected());
      }
      if (imagePreference && imagePreference.isPreferred()) {
        appendPreferencePreview(userMessageElement, true);
      }
      const fileInput = wrapper.querySelector('.ai-moody-assistant__composer-file-input');
      if (uploader && uploader.hasFiles()) {
        const fileCount = uploader.getFiles().length;
        appendUserMessage(wrapper, 'Attached ' + fileCount + ' file' + (fileCount === 1 ? '' : 's') + ' for this request.');
      }
      appendThinkingMessage(wrapper);
      if (composer && composer.editor) {
        composer.editor.setAttribute('aria-busy', 'true');
        composer.editor.setAttribute('contenteditable', 'false');
      }
      input.readOnly = true;
      submit.disabled = true;
      submit.classList.add('is-disabled', 'is-loading');
      submit.dataset.idleLabel = submit.value || submit.textContent || 'Send';
      if ('value' in submit) {
        submit.value = 'Working…';
      }
      else {
        submit.textContent = 'Working…';
      }

      const payload = new FormData(form);
      if (uploader) {
        payload.delete('attachments[]');
        const files = uploader.getFiles();
        console.log(uploaderDebugPrefix, 'appending files to payload', {
          count: files.length,
          files: files.map((file) => file.name)
        });
        files.forEach((file) => {
          payload.append('attachments[]', file, file.name);
        });
      }

      const retryCurrentRequest = () => {
        if (activeAbortController) {
          activeAbortController.abort();
        }
        isSubmitting = false;
        submitRequest();
      };

      const abortController = new AbortController();
      activeAbortController = abortController;
      const watchdog = createStreamWatchdog(wrapper, () => {
        updateThinkingMessage(
          wrapper,
          'The live stream has been quiet for over 2 minutes. The request may be stalled. You can retry now.',
          true,
          retryCurrentRequest
        );
        if (composer && composer.editor) {
          composer.editor.setAttribute('contenteditable', 'true');
          composer.editor.removeAttribute('aria-busy');
        }
        input.readOnly = false;
        submit.disabled = false;
        submit.classList.remove('is-disabled', 'is-loading');
        if ('value' in submit) {
          submit.value = submit.dataset.idleLabel || 'Send';
        }
        else {
          submit.textContent = submit.dataset.idleLabel || 'Send';
        }
      });

      try {
        const response = await window.fetch(streamSettings.streamUrl, {
          method: 'POST',
          body: payload,
          headers: {
            'X-CSRF-Token': streamSettings.csrfToken
          },
          credentials: 'same-origin',
          signal: abortController.signal
        });

        if (!response.ok) {
          const message = (await response.text()).trim();
          throw new Error(message || 'AI chat request failed with HTTP ' + response.status + '.');
        }

        setComposerText(wrapper, '');
        if (uploader) {
          uploader.clear();
        }
        if (blockReferences) {
          blockReferences.clear();
        }
        if (imagePreference) {
          imagePreference.clear();
        }
        refreshTokenCounter();
        await consumeStream(response, wrapper, {
          onActivity() {
            watchdog.markActivity();
          }
        });
        watchdog.stop();
      }
      catch (error) {
        watchdog.stop();
        if (error && error.name === 'AbortError') {
          return;
        }
        updateThinkingMessage(wrapper, 'I could not complete that request: ' + error.message, true, () => {
          submitRequest();
        });
        if (composer && composer.editor) {
          composer.editor.setAttribute('contenteditable', 'true');
          composer.editor.removeAttribute('aria-busy');
        }
        input.readOnly = false;
        submit.disabled = false;
        submit.classList.remove('is-disabled', 'is-loading');
        if ('value' in submit) {
          submit.value = submit.dataset.idleLabel || 'Send';
        }
        else {
          submit.textContent = submit.dataset.idleLabel || 'Send';
        }
        refreshTokenCounter();
      }
      finally {
        activeAbortController = null;
        isSubmitting = false;
      }
    };

    form.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' || (!event.metaKey && !event.ctrlKey) || event.isComposing) {
        return;
      }

      event.preventDefault();
      if (!submit.disabled) {
        form.requestSubmit(submit);
      }
    });

    form.addEventListener('submit', async (event) => {
      // Media Library submits this form programmatically without a submitter.
      // Intercept only the assistant's own Send button so Drupal's AJAX form
      // elements can complete their normal callbacks.
      if (event.submitter !== submit) {
        return;
      }
      event.preventDefault();
      await submitRequest();
    });
  }

  async function resumeDeferredRequestIfNeeded(wrapper) {
    const streamSettings = drupalSettings.moodyAiAssistant || {};
    if (!streamSettings.streamUrl || !streamSettings.csrfToken) {
      return;
    }

    const url = new URL(window.location.href);
    const resumeThreadId = url.searchParams.get('moody_ai_assistant_resume_thread');
    const resumeActionId = url.searchParams.get('moody_ai_assistant_resume_action');
    if (!resumeThreadId || !resumeActionId) {
      return;
    }

    if (wrapper.dataset.aiAssistantResumeStarted === 'true') {
      return;
    }

    wrapper.dataset.aiAssistantResumeStarted = 'true';
    setOpenState(wrapper, true);
    appendThinkingMessage(wrapper);
    updateThinkingMessage(wrapper, 'Continuing this request in Layout Builder...');

    const form = wrapper.querySelector('.ai-moody-assistant__form form');
    const payload = new FormData();
    if (form) {
      const entityType = form.querySelector('input[name="entity_type"]');
      const entityId = form.querySelector('input[name="entity_id"]');
      const layoutContext = form.querySelector('input[name="is_layout_builder_context"]');
      if (entityType) {
        payload.append('entity_type', entityType.value);
      }
      if (entityId) {
        payload.append('entity_id', entityId.value);
      }
      if (layoutContext) {
        payload.append('is_layout_builder_context', layoutContext.value);
      }
    }
    payload.append('resume_thread_id', resumeThreadId);
    payload.append('resume_action_id', resumeActionId);

    url.searchParams.delete('moody_ai_assistant_resume_thread');
    url.searchParams.delete('moody_ai_assistant_resume_action');
    window.history.replaceState({}, '', url.toString());

    try {
      const response = await window.fetch(streamSettings.streamUrl, {
        method: 'POST',
        body: payload,
        headers: {
          'X-CSRF-Token': streamSettings.csrfToken
        },
        credentials: 'same-origin'
      });

      if (!response.ok) {
        const message = (await response.text()).trim();
        throw new Error(message || 'AI resume request failed with HTTP ' + response.status + '.');
      }

      const watchdog = createStreamWatchdog(wrapper, () => {
        updateThinkingMessage(
          wrapper,
          'The Layout Builder resume stream has been quiet for over 2 minutes. The request may be stalled. You can retry now.',
          true,
          () => {
            window.location.reload();
          }
        );
      });

      await consumeStream(response, wrapper, {
        onActivity() {
          watchdog.markActivity();
        }
      });
      watchdog.stop();
    }
    catch (error) {
      updateThinkingMessage(wrapper, 'I could not resume that request automatically: ' + error.message, true);
    }
  }

  function setMinimizedState(wrapper, isMinimized) {
    const minimizeButton = wrapper.querySelector('[data-ai-assistant-minimize]');
    if (!minimizeButton) {
      return;
    }

    wrapper.classList.toggle('is-minimized', isMinimized);
    minimizeButton.setAttribute('aria-pressed', isMinimized ? 'true' : 'false');
    minimizeButton.setAttribute(
      'aria-label',
      isMinimized
        ? minimizeButton.dataset.aiAssistantRestoreLabel
        : minimizeButton.dataset.aiAssistantMinimizeLabel
    );
    writeAssistantPreferences(wrapper, { minimized: isMinimized });
  }

  function setOpenState(wrapper, isOpen) {
    const toggle = wrapper.querySelector('.ai-moody-assistant__toggle');
    const panel = wrapper.querySelector('.ai-moody-assistant__panel');
    if (!toggle || !panel) {
      return;
    }

    if (isOpen) {
      setMinimizedState(wrapper, false);
    }
    toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    panel.hidden = !isOpen;
    wrapper.classList.toggle('is-open', isOpen);
    if (isOpen) {
      scrollHistoryToBottom(wrapper);
    }

    const storageKey = getOpenStateStorageKey(wrapper);
    if (storageKey) {
      writeLocalStorage(storageKey, isOpen ? 'open' : 'closed');
    }
  }

  Drupal.behaviors.moodyAiAssistant = {
    attach(context) {
      once('ai-blocks-chat-assistant', '.ai-moody-assistant', context).forEach((wrapper) => {
        console.log(assistantAssetMarker, 'behavior attach', {
          assistantKey: wrapper.dataset.aiAssistantKey || ''
        });
        console.log(uploaderDebugPrefix, 'behavior attached', {
          assistantKey: wrapper.dataset.aiAssistantKey || ''
        });
        const toggle = wrapper.querySelector('.ai-moody-assistant__toggle');
        const minimizeButton = wrapper.querySelector('[data-ai-assistant-minimize]');
        const closeButton = wrapper.querySelector('.ai-moody-assistant__close');
        const storageKey = getOpenStateStorageKey(wrapper);
        const preferences = readAssistantPreferences(wrapper);

        bindPanelResizeControls(wrapper);

        if (storageKey && readLocalStorage(storageKey) === 'open') {
          setOpenState(wrapper, true);
        }
        else {
          setMinimizedState(wrapper, preferences.minimized === true);
          scrollHistoryToBottom(wrapper);
        }

        if (toggle) {
          toggle.addEventListener('click', () => {
            const willOpen = toggle.getAttribute('aria-expanded') !== 'true';
            setOpenState(wrapper, willOpen);
            if (willOpen) {
              focusComposer(wrapper);
            }
          });
        }

        if (minimizeButton) {
          minimizeButton.addEventListener('click', () => {
            const willMinimize = !wrapper.classList.contains('is-minimized');
            setMinimizedState(wrapper, willMinimize);
            if (!willMinimize) {
              toggle?.focus();
            }
          });
        }

        document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape' && wrapper.classList.contains('is-open')) {
            const tokenPopover = wrapper.querySelector('[data-ai-assistant-token-counter-popover]');
            if (tokenPopover && !tokenPopover.hidden) {
              return;
            }
            if (event.target instanceof Element && event.target.closest('dialog, .ui-dialog[role="dialog"]')) {
              return;
            }
            const childDialogOpen = Array.from(document.querySelectorAll('dialog[open], .ui-dialog[role="dialog"]')).some((dialog) => {
              return !wrapper.contains(dialog) && dialog.getClientRects().length > 0;
            });
            if (childDialogOpen) {
              return;
            }
            setOpenState(wrapper, false);
            toggle?.focus();
          }
        });

        bindConversationSearch(wrapper);
        bindStarterPrompts(wrapper);
        bindFormSubmission(wrapper);
        resumeDeferredRequestIfNeeded(wrapper);

        if (closeButton) {
          closeButton.addEventListener('click', () => {
            setOpenState(wrapper, false);
            toggle?.focus();
          });
        }
      });
    }
  };
})(Drupal, drupalSettings, once);
