import { Plugin } from 'ckeditor5/src/core';
import { ButtonView } from 'ckeditor5/src/ui';

const icon = '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 2.25a.75.75 0 0 1 .72.54l1.08 3.72a5.25 5.25 0 0 0 3.58 3.58l3.83 1.11a.75.75 0 0 1 0 1.44l-3.83 1.11a5.25 5.25 0 0 0-3.58 3.58l-1.08 3.72a.75.75 0 0 1-1.44 0l-1.08-3.72a5.25 5.25 0 0 0-3.58-3.58l-3.83-1.11a.75.75 0 0 1 0-1.44l3.83-1.11a5.25 5.25 0 0 0 3.58-3.58l1.08-3.72a.75.75 0 0 1 .72-.54Zm0 3.36-.36 1.24a6.75 6.75 0 0 1-4.6 4.6l-1.56.45 1.56.45a6.75 6.75 0 0 1 4.6 4.6l.36 1.24.36-1.24a6.75 6.75 0 0 1 4.6-4.6l1.56-.45-1.56-.45a6.75 6.75 0 0 1-4.6-4.6L12 5.61Z"/></svg>';

function appendOptions(select, options, selected) {
  (options || []).forEach(([value, label]) => {
    const option = document.createElement('option');
    option.value = value;
    option.textContent = label;
    option.selected = value === selected;
    select.appendChild(option);
  });
}

function selectionSnapshot(editor) {
  try {
    return editor.model.createSelection(editor.model.document.selection);
  }
  catch (error) {
    return null;
  }
}

function formatBytes(bytes) {
  return bytes < 1048576
    ? `${Math.ceil(bytes / 1024)} KB`
    : `${(bytes / 1048576).toFixed(1)} MB`;
}

function showEditorStatus(editor, message, isError = false) {
  const parent = editor.ui.view.element.parentElement;
  const existing = parent && parent.querySelector('[data-moody-ai-editor-status]');
  if (existing) existing.remove();

  const status = document.createElement('p');
  status.dataset.moodyAiEditorStatus = 'true';
  status.className = isError
    ? 'moody-ai-dialog__status moody-ai-dialog__status--error'
    : 'moody-ai-ui__offline';
  status.setAttribute('role', isError ? 'alert' : 'status');
  status.textContent = message;
  editor.ui.view.element.after(status);
}

function openDialog(settings, mediaLibrary) {
  return new Promise(resolve => {
    const dialog = document.createElement('dialog');
    dialog.className = 'moody-ai-dialog';
    dialog.innerHTML = `
      <form class="moody-ai-dialog__form">
        <header>
          <h2 class="moody-ai-dialog__title">Generate HTML with Moody AI</h2>
          <p class="moody-ai-dialog__description">Describe the content and structure you want. Review everything before inserting it.</p>
        </header>
        <p class="moody-ai-dialog__privacy moody-ai-ui__privacy"><strong>Protect private information:</strong> <span data-privacy-copy></span></p>
        <div class="moody-ai-dialog__choices moody-ai-ui__choices">
          <label class="moody-ai-dialog__field moody-ai-ui__field">
            <span>Provider</span>
            <select class="moody-ai-dialog__select moody-ai-ui__control" name="provider"></select>
          </label>
          <label class="moody-ai-dialog__field moody-ai-ui__field">
            <span>Model</span>
            <select class="moody-ai-dialog__select moody-ai-ui__control" name="model"></select>
          </label>
        </div>
        <details class="moody-ai-dialog__ideas">
          <summary>Ideas</summary>
          <div class="moody-ai-dialog__idea-list" data-idea-list></div>
        </details>
        <label class="moody-ai-dialog__field moody-ai-ui__field">
          <span>What should the editor create?</span>
          <textarea class="moody-ai-dialog__textarea moody-ai-ui__control" name="prompt" required></textarea>
        </label>
        <div class="moody-ai-dialog__prompt-options">
          <label class="moody-ai-dialog__image-preference"><input name="preferAiImages" type="checkbox"> Create image</label>
          <span class="moody-ai-dialog__token-estimate" data-token-estimate>~0 tokens</span>
        </div>
        <label class="moody-ai-dialog__field moody-ai-ui__field">
          <span>Reference files <small>(optional)</small></span>
          <input class="moody-ai-dialog__file moody-ai-ui__control" name="attachments" type="file" multiple aria-describedby="moody-ai-attachment-help">
          <small class="moody-ai-ui__help" id="moody-ai-attachment-help">Choose or drop files, or paste an image while this dialog is focused. Files are stored privately for this user and sent only when you generate a preview.</small>
        </label>
        <div class="moody-ai-dialog__attachment-summary" hidden>
          <span></span>
          <button class="moody-ai-dialog__clear" type="button">Clear attachments</button>
        </div>
        <section class="moody-ai-dialog__existing-media">
          <div class="moody-ai-dialog__media-heading">
            <span>Existing Media <small>(optional)</small></span>
            <button class="moody-ai-dialog__button moody-ai-dialog__button--compact" type="button" data-action="media">Add from Media Library</button>
          </div>
          <small>Select whether each item is inspiration only or may be inserted when your prompt asks for it.</small>
          <div class="moody-ai-dialog__media-list" hidden></div>
        </section>
        <p class="moody-ai-dialog__status" role="status" aria-live="polite"></p>
        <section class="moody-ai-dialog__preview-wrap" hidden>
          <span class="moody-ai-dialog__preview-label">Generated preview</span>
          <div class="moody-ai-dialog__preview"></div>
        </section>
        <div class="moody-ai-dialog__actions">
          <button class="moody-ai-dialog__button" type="button" data-action="cancel">Cancel</button>
          <button class="moody-ai-dialog__button" type="submit" data-action="generate">Generate preview</button>
          <button class="moody-ai-dialog__button moody-ai-dialog__button--primary" type="button" data-action="insert" disabled>Insert HTML</button>
        </div>
      </form>
    `;

    document.body.appendChild(dialog);
    const form = dialog.querySelector('form');
    const privacyCopy = dialog.querySelector('[data-privacy-copy]');
    const provider = dialog.querySelector('[name="provider"]');
    const model = dialog.querySelector('[name="model"]');
    const prompt = dialog.querySelector('[name="prompt"]');
    const preferAiImages = dialog.querySelector('[name="preferAiImages"]');
    const tokenEstimate = dialog.querySelector('[data-token-estimate]');
    const ideaList = dialog.querySelector('[data-idea-list]');
    const attachmentInput = dialog.querySelector('[name="attachments"]');
    const attachmentSummary = dialog.querySelector('.moody-ai-dialog__attachment-summary');
    const attachmentSummaryText = attachmentSummary.querySelector('span');
    const clearAttachments = dialog.querySelector('.moody-ai-dialog__clear');
    const addMedia = dialog.querySelector('[data-action="media"]');
    const mediaList = dialog.querySelector('.moody-ai-dialog__media-list');
    const status = dialog.querySelector('[role="status"]');
    const previewWrap = dialog.querySelector('.moody-ai-dialog__preview-wrap');
    const preview = dialog.querySelector('.moody-ai-dialog__preview');
    const generate = dialog.querySelector('[data-action="generate"]');
    const insert = dialog.querySelector('[data-action="insert"]');
    const cancel = dialog.querySelector('[data-action="cancel"]');
    let generatedHtml = '';
    let queuedAttachments = [];
    let selectedMedia = [];
    let previewObjectUrls = [];
    let settled = false;

    appendOptions(provider, JSON.parse(settings.providersJson || '[]'), settings.defaultProvider);
    appendOptions(model, JSON.parse(settings.modelsJson || '[]'), settings.defaultModel);
    prompt.maxLength = settings.maxPromptCharacters || 2000;
    attachmentInput.accept = settings.attachmentAccept || '';
    privacyCopy.textContent = settings.privacyNotice || 'Your prompt and selected references may be sent to the configured AI provider. Do not include restricted information.';
    addMedia.hidden = !mediaLibrary || !mediaLibrary.libraryURL || typeof mediaLibrary.openDialog !== 'function';

    [
      ['Draft a section', 'Draft a concise, accessible section with an H2 heading and web-friendly supporting copy.'],
      ['Summarize references', 'Summarize the selected references into clear, accessible web content with a logical heading structure.'],
      ['Create a callout', 'Create a concise callout with a strong heading, supporting copy, and a descriptive call-to-action link.'],
    ].forEach(([label, value]) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'moody-ai-dialog__idea';
      button.textContent = label;
      button.addEventListener('click', () => {
        prompt.value = value;
        prompt.dispatchEvent(new Event('input'));
        prompt.focus();
      });
      ideaList.append(button);
    });

    const selectedFiles = () => queuedAttachments.map(attachment => attachment.file);
    const updateTokenEstimate = () => {
      const references = [
        ...selectedFiles().map(file => `${file.name} ${file.type} ${file.size}`),
        ...selectedMedia.map(item => `${item.label} ${item.type} ${item.intent}`),
      ].join('\n');
      const imagePreference = preferAiImages.checked ? 'Create image enabled' : '';
      const characters = [prompt.value, references, imagePreference].filter(Boolean).join('\n\n').length;
      tokenEstimate.textContent = `~${characters ? Math.max(1, Math.ceil(characters / 4)) : 0} tokens`;
    };
    const clearPreview = () => {
      previewObjectUrls.forEach(url => URL.revokeObjectURL(url));
      previewObjectUrls = [];
      generatedHtml = '';
      preview.textContent = '';
      previewWrap.hidden = true;
      insert.disabled = true;
    };
    const updateAttachmentSummary = () => {
      const files = selectedFiles();
      attachmentSummary.hidden = files.length === 0;
      attachmentSummaryText.textContent = files.length
        ? `${files.length} selected: ${files.map(file => file.name).join(', ')}`
        : '';
      addMedia.disabled = selectedMedia.length + files.length >= (settings.maxAttachments || 3);
      updateTokenEstimate();
    };

    const validateAttachments = files => {
      if (files.length + selectedMedia.length > (settings.maxAttachments || 3)) {
        throw new Error(`Select no more than ${settings.maxAttachments || 3} total files and Media items.`);
      }
      const oversized = files.find(file => file.size > (settings.maxAttachmentBytes || 5242880));
      if (oversized) {
        throw new Error(`${oversized.name} exceeds the ${formatBytes(settings.maxAttachmentBytes || 5242880)} file limit.`);
      }
      const total = files.reduce((sum, file) => sum + file.size, 0);
      if (total > (settings.maxTotalAttachmentBytes || 10485760)) {
        throw new Error(`Attachments exceed the ${formatBytes(settings.maxTotalAttachmentBytes || 10485760)} combined limit.`);
      }
    };

    const addAttachments = files => {
      const existing = new Set(queuedAttachments.map(({ file }) => `${file.name}:${file.size}:${file.lastModified}`));
      const additions = files
        .filter(file => !existing.has(`${file.name}:${file.size}:${file.lastModified}`))
        .map(file => ({ file, upload: null }));
      const next = [...queuedAttachments, ...additions];
      validateAttachments(next.map(attachment => attachment.file));
      queuedAttachments = next;
      clearPreview();
      updateAttachmentSummary();
    };

    const mediaPayload = () => selectedMedia.map(item => ({
      uuid: item.uuid,
      intent: item.intent,
    }));

    const updateMediaList = () => {
      mediaList.textContent = '';
      mediaList.hidden = selectedMedia.length === 0;
      selectedMedia.forEach((item, index) => {
        const row = document.createElement('div');
        row.className = 'moody-ai-dialog__media-item';

        const description = document.createElement('span');
        const name = document.createElement('strong');
        name.textContent = item.label;
        description.append(name, document.createTextNode(` — ${item.type}${item.contextAvailable ? '' : ' (title and type only)'}`));

        const intent = document.createElement('select');
        intent.className = 'moody-ai-dialog__select moody-ai-dialog__media-intent';
        intent.setAttribute('aria-label', `How Moody AI may use ${item.label}`);
        appendOptions(intent, [
          ['inspiration', 'Inspiration only'],
          ['content', 'May insert in content'],
        ], item.intent);
        intent.addEventListener('change', () => {
          selectedMedia[index].intent = intent.value;
          clearPreview();
          status.textContent = intent.value === 'content'
            ? `${item.label} may be inserted when your prompt asks for it.`
            : `${item.label} will be used only as inspiration.`;
        });

        const remove = document.createElement('button');
        remove.className = 'moody-ai-dialog__clear';
        remove.type = 'button';
        remove.textContent = 'Remove';
        remove.setAttribute('aria-label', `Remove ${item.label}`);
        remove.addEventListener('click', () => {
          selectedMedia.splice(index, 1);
          clearPreview();
          updateMediaList();
          updateAttachmentSummary();
          addMedia.focus();
        });
        row.append(description, intent, remove);
        mediaList.append(row);
      });
      addMedia.disabled = selectedMedia.length + selectedFiles().length >= (settings.maxAttachments || 3);
      updateTokenEstimate();
    };

    const addMediaSelection = async uuid => {
      if (!uuid || selectedMedia.some(item => item.uuid === uuid)) return;
      if (selectedMedia.length + selectedFiles().length >= (settings.maxAttachments || 3)) {
        throw new Error(`Select no more than ${settings.maxAttachments || 3} total files and Media items.`);
      }
      const response = await fetch(settings.mediaInfoEndpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-Moody-AI-Token': settings.csrfToken,
        },
        body: JSON.stringify({ uuid }),
      });
      const data = await response.json();
      if (!response.ok || !data.uuid) {
        throw new Error(data.message || 'That Media item could not be selected.');
      }
      selectedMedia.push({ ...data, intent: 'inspiration' });
      clearPreview();
      updateMediaList();
      updateAttachmentSummary();
      status.textContent = `${data.label} added as inspiration. Choose “May insert in content” if it should appear in the result.`;
    };

    const openMediaLibrary = () => {
      let selection = Promise.resolve();
      const reopen = async () => {
        try {
          await selection;
          status.classList.remove('moody-ai-dialog__status--error');
        }
        catch (error) {
          status.classList.add('moody-ai-dialog__status--error');
          status.textContent = error.message || 'That Media item could not be selected.';
        }
        if (!settled && !dialog.open) {
          dialog.showModal();
          addMedia.focus();
        }
      };
      window.addEventListener('dialog:afterclose', reopen, { once: true });
      dialog.close();
      try {
        mediaLibrary.openDialog(mediaLibrary.libraryURL, values => {
          selection = addMediaSelection(values && values.attributes && values.attributes['data-entity-uuid']);
        }, mediaLibrary.dialogSettings || {});
      }
      catch (error) {
        window.removeEventListener('dialog:afterclose', reopen);
        selection = Promise.reject(error);
        reopen();
      }
    };

    const uploadAttachments = async () => {
      for (let index = 0; index < queuedAttachments.length; index += 1) {
        if (queuedAttachments[index].upload) continue;
        status.textContent = `Uploading attachment ${index + 1} of ${queuedAttachments.length}…`;
        const body = new FormData();
        body.append('upload', queuedAttachments[index].file);
        const response = await fetch(settings.uploadEndpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-Moody-AI-Token': settings.csrfToken },
          body,
        });
        const data = await response.json();
        if (!response.ok || !data.id) {
          throw new Error(data.message || `${queuedAttachments[index].file.name} could not be uploaded.`);
        }
        queuedAttachments[index].upload = data;
      }
    };

    const renderPreview = html => {
      previewObjectUrls.forEach(url => URL.revokeObjectURL(url));
      previewObjectUrls = [];
      preview.innerHTML = html;
      const uploads = Array.from(preview.querySelectorAll('drupal-media[data-moody-ai-attachment]'));
      uploads.forEach(placeholder => {
        const index = Number.parseInt(placeholder.dataset.moodyAiAttachment, 10) - 1;
        const file = queuedAttachments[index] && queuedAttachments[index].file;
        if (!file || !file.type.startsWith('image/')) return;

        const image = document.createElement('img');
        const url = URL.createObjectURL(file);
        previewObjectUrls.push(url);
        image.className = 'moody-ai-dialog__media-preview';
        image.src = url;
        image.alt = placeholder.dataset.moodyAiAlt || '';
        placeholder.replaceWith(image);
      });
      const existing = Array.from(preview.querySelectorAll('drupal-media[data-moody-ai-media]'));
      existing.forEach(placeholder => {
        const index = Number.parseInt(placeholder.dataset.moodyAiMedia, 10) - 1;
        const item = selectedMedia[index];
        if (!item) return;
        const media = document.createElement('div');
        media.className = 'moody-ai-dialog__existing-media-preview';
        media.textContent = `Existing Media: ${item.label} (${item.type})`;
        placeholder.replaceWith(media);
      });
      const generated = Array.from(preview.querySelectorAll('drupal-media[data-moody-ai-generated-image]'));
      generated.forEach(placeholder => {
        const media = document.createElement('div');
        media.className = 'moody-ai-dialog__existing-media-preview';
        media.textContent = `New AI image: ${placeholder.dataset.moodyAiAlt || 'Generated image'} (created after you approve insertion)`;
        placeholder.replaceWith(media);
      });
      return { uploads: uploads.length, existing: existing.length, generated: generated.length };
    };

    const close = result => {
      if (settled) return;
      settled = true;
      previewObjectUrls.forEach(url => URL.revokeObjectURL(url));
      dialog.close();
      dialog.remove();
      resolve(result);
    };

    cancel.addEventListener('click', () => close(null));
    addMedia.addEventListener('click', openMediaLibrary);
    attachmentInput.addEventListener('change', () => {
      try {
        addAttachments(Array.from(attachmentInput.files || []));
        status.classList.remove('moody-ai-dialog__status--error');
        status.textContent = '';
      }
      catch (error) {
        status.classList.add('moody-ai-dialog__status--error');
        status.textContent = error.message;
      }
      attachmentInput.value = '';
    });
    clearAttachments.addEventListener('click', () => {
      queuedAttachments = [];
      attachmentInput.value = '';
      clearPreview();
      updateAttachmentSummary();
      attachmentInput.focus();
    });
    prompt.addEventListener('input', () => {
      clearPreview();
      updateTokenEstimate();
    });
    preferAiImages.addEventListener('change', () => {
      clearPreview();
      updateTokenEstimate();
    });
    dialog.addEventListener('dragover', event => {
      if (event.dataTransfer && event.dataTransfer.types.includes('Files')) {
        event.preventDefault();
      }
    });
    dialog.addEventListener('drop', event => {
      const files = Array.from((event.dataTransfer && event.dataTransfer.files) || []);
      if (!files.length) return;
      event.preventDefault();
      try {
        addAttachments(files);
        status.classList.remove('moody-ai-dialog__status--error');
        status.textContent = `${files.length} dropped file${files.length === 1 ? '' : 's'} added.`;
      }
      catch (error) {
        status.classList.add('moody-ai-dialog__status--error');
        status.textContent = error.message;
      }
    });
    dialog.addEventListener('paste', event => {
      const clipboardImages = Array.from((event.clipboardData && event.clipboardData.files) || [])
        .filter(file => file.type.startsWith('image/'));
      if (!clipboardImages.length) return;

      event.preventDefault();
      try {
        const extensionByType = {
          'image/gif': 'gif',
          'image/jpeg': 'jpg',
          'image/png': 'png',
          'image/webp': 'webp',
        };
        const allowed = new Set((settings.attachmentAccept || '').split(',').map(value => value.replace(/^\./, '')));
        const pasted = clipboardImages.map((file, index) => {
          const extension = extensionByType[file.type];
          if (!extension || !allowed.has(extension)) {
            throw new Error('That clipboard image format is not supported. Paste a PNG, GIF, JPEG, or WebP image.');
          }
          return new File(
            [file],
            `pasted-image-${Date.now()}-${index + 1}.${extension}`,
            { type: file.type, lastModified: Date.now() },
          );
        });
        addAttachments(pasted);
        status.classList.remove('moody-ai-dialog__status--error');
        status.textContent = `${pasted.length} clipboard image${pasted.length === 1 ? '' : 's'} added.`;
      }
      catch (error) {
        status.classList.add('moody-ai-dialog__status--error');
        status.textContent = error.message;
      }
    });
    dialog.addEventListener('cancel', event => {
      event.preventDefault();
      close(null);
    });
    insert.addEventListener('click', async () => {
      if (!generatedHtml) return;
      status.classList.remove('moody-ai-dialog__status--error');
      status.textContent = 'Preparing content for insertion…';
      generate.disabled = true;
      insert.disabled = true;
      try {
        const response = await fetch(settings.finalizeEndpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-Moody-AI-Token': settings.csrfToken,
          },
          body: JSON.stringify({
            html: generatedHtml,
            attachments: queuedAttachments.map(attachment => attachment.upload.id),
            media: mediaPayload(),
            preferAiImages: preferAiImages.checked,
          }),
        });
        const data = await response.json();
        if (!response.ok || !data.html) {
          throw new Error(data.message || 'Moody AI could not prepare the content for insertion.');
        }
        close(data.html);
      }
      catch (error) {
        status.classList.add('moody-ai-dialog__status--error');
        status.textContent = error.message || 'Moody AI could not prepare the content for insertion.';
        generate.disabled = false;
        insert.disabled = false;
      }
    });

    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (!prompt.reportValidity()) return;

      status.classList.remove('moody-ai-dialog__status--error');
      status.textContent = 'Generating a preview…';
      generate.disabled = true;
      insert.disabled = true;

      try {
        validateAttachments(selectedFiles());
        await uploadAttachments();
        status.textContent = 'Generating a preview…';
        const response = await fetch(settings.endpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-Moody-AI-Token': settings.csrfToken,
          },
          body: JSON.stringify({
            provider: provider.value,
            model: model.value,
            prompt: prompt.value.trim(),
            attachments: queuedAttachments.map(attachment => attachment.upload.id),
            media: mediaPayload(),
            preferAiImages: preferAiImages.checked,
          }),
        });
        const data = await response.json();
        if (!response.ok || !data.html) {
          throw new Error(data.message || 'Moody AI could not generate a preview.');
        }

        generatedHtml = data.html;
        const mediaCount = renderPreview(generatedHtml);
        previewWrap.hidden = false;
        const changes = [];
        if (mediaCount.existing) {
          changes.push(`add ${mediaCount.existing} existing Media item${mediaCount.existing === 1 ? '' : 's'}`);
        }
        if (mediaCount.uploads) {
          changes.push(`create ${mediaCount.uploads} new Media item${mediaCount.uploads === 1 ? '' : 's'}`);
        }
        if (mediaCount.generated) {
          changes.push(`create ${mediaCount.generated} new AI image${mediaCount.generated === 1 ? '' : 's'} as Media`);
        }
        status.textContent = changes.length
          ? `Preview ready. Inserting will ${changes.join(' and ')}. Review everything carefully.`
          : 'Preview ready. Review it carefully before inserting.';
        insert.disabled = false;
      }
      catch (error) {
        status.classList.add('moody-ai-dialog__status--error');
        status.textContent = error.message || 'Moody AI could not generate a preview.';
      }
      finally {
        generate.disabled = false;
      }
    });

    dialog.showModal();
    updateTokenEstimate();
    prompt.focus();
  });
}

export default class MoodyAiGenerate extends Plugin {
  init() {
    const editor = this.editor;
    const settings = editor.config.get('moodyAi');
    const enabled = Boolean(settings && settings.enabled);
    const configured = Boolean(settings && settings.configured);

    editor.ui.componentFactory.add('moodyAiGenerate', () => {
      const button = new ButtonView();
      button.set({
        label: !settings
          ? 'Moody AI is unavailable'
          : !enabled
            ? settings.offlineMessage
            : configured
              ? 'Generate with Moody AI'
              : 'Moody AI is not configured',
        icon,
        tooltip: true,
        isVisible: Boolean(settings),
      });
      button.bind('isEnabled').to(editor, 'isReadOnly', isReadOnly => Boolean(settings && (configured || !enabled)) && !isReadOnly);

      button.on('execute', async () => {
        if (!enabled) {
          showEditorStatus(editor, settings.offlineMessage);
          return;
        }
        try {
          const insertionSelection = selectionSnapshot(editor);
          const html = await openDialog(settings, editor.config.get('drupalMedia'));
          if (!html) return;

          const fragment = editor.data.parse(html);
          editor.model.change(() => {
            editor.model.insertContent(fragment, insertionSelection || undefined);
          });
          editor.editing.view.focus();
        }
        catch (error) {
          showEditorStatus(editor, 'Moody AI could not open. Reload the page and try again.', true);
        }
      });

      return button;
    });
  }
}
