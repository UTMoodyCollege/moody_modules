import { Plugin } from 'ckeditor5/src/core';
import { ButtonView } from 'ckeditor5/src/ui';
import icon from '../../../../icons/moody-image-caption.svg';

const DEFAULT_VALUES = {
  text: '',
  color: 'gray',
};

const COLOR_LABELS = {
  gray: 'Default gray',
  black: 'Black',
  'burnt-orange': 'Burnt orange',
};

function escapeHtml(value) {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function getSelectionSnapshot(editor) {
  try {
    return editor.model.createSelection(editor.model.document.selection);
  }
  catch (error) {
    return null;
  }
}

function buildCaptionHtml(values) {
  const lines = values.text
    .split(/\r?\n/)
    .map(line => escapeHtml(line.trim()))
    .filter(Boolean);

  const content = lines.length ? lines.join('<br>') : 'Add image caption here.';

  return `<div class="moody-image-caption moody-image-caption--${values.color}">${content}</div>`;
}

function buildPreviewText(values) {
  return values.text.trim() || '"RTF Live!" note cards. Photo by Ivan Rocha';
}

function openImageCaptionDialog(initialValues) {
  return new Promise(resolve => {
    const dialog = document.createElement('dialog');
    dialog.className = 'moody-image-caption-dialog';
    dialog.innerHTML = `
      <form class="moody-image-caption-dialog__form" method="dialog">
        <div class="moody-image-caption-dialog__header">
          <h2 class="moody-image-caption-dialog__title">Image Caption</h2>
          <p class="moody-image-caption-dialog__description">Add a centered caption or photo credit line beneath an image with a consistent Moody style.</p>
        </div>
        <label class="moody-image-caption-dialog__field">
          <span class="moody-image-caption-dialog__label">Caption text</span>
          <textarea class="moody-image-caption-dialog__textarea" name="text" placeholder='Example: "RTF Live!" note cards. Photo by Ivan Rocha'></textarea>
        </label>
        <label class="moody-image-caption-dialog__field">
          <span class="moody-image-caption-dialog__label">Caption color</span>
          <select class="moody-image-caption-dialog__select" name="color">
            <option value="gray">Default gray</option>
            <option value="black">Black</option>
            <option value="burnt-orange">Burnt orange</option>
          </select>
        </label>
        <p class="moody-image-caption-dialog__hint">Use gray for standard image credits. Black or burnt orange can be used when the caption needs more visual weight.</p>
        <div class="moody-image-caption-dialog__preview">
          <span class="moody-image-caption-dialog__preview-label">Preview</span>
          <p class="moody-image-caption-dialog__preview-text moody-image-caption moody-image-caption--gray" data-role="preview"></p>
        </div>
        <div class="moody-image-caption-dialog__actions">
          <button class="moody-image-caption-dialog__button moody-image-caption-dialog__button--secondary" type="button" value="cancel">Cancel</button>
          <button class="moody-image-caption-dialog__button moody-image-caption-dialog__button--primary" type="submit" value="apply">Insert Caption</button>
        </div>
      </form>
    `;

    document.body.appendChild(dialog);

    const textField = dialog.querySelector('[name="text"]');
    const colorField = dialog.querySelector('[name="color"]');
    const preview = dialog.querySelector('[data-role="preview"]');
    const cancelButton = dialog.querySelector('[value="cancel"]');

    const values = {
      text: initialValues.text,
      color: initialValues.color,
    };

    const syncFields = () => {
      textField.value = values.text;
      colorField.value = values.color;
      preview.className = `moody-image-caption-dialog__preview-text moody-image-caption moody-image-caption--${values.color}`;
      preview.innerHTML = buildPreviewText(values)
        .split(/\r?\n/)
        .map(line => escapeHtml(line.trim()))
        .filter(Boolean)
        .join('<br>');
    };

    let isSettled = false;

    const cleanup = result => {
      if (isSettled) {
        return;
      }

      isSettled = true;
      dialog.remove();
      resolve(result);
    };

    textField.addEventListener('input', () => {
      values.text = textField.value;
      syncFields();
    });

    colorField.addEventListener('change', () => {
      values.color = colorField.value;
      syncFields();
    });

    cancelButton.addEventListener('click', () => {
      dialog.close('cancel');
    });

    dialog.addEventListener('cancel', event => {
      event.preventDefault();
      dialog.close('cancel');
    });

    dialog.addEventListener('close', () => {
      if (dialog.returnValue === 'apply') {
        cleanup({
          text: values.text.trim(),
          color: values.color,
        });
      }
      else {
        cleanup(null);
      }
    }, { once: true });

    syncFields();
    dialog.showModal();
    textField.focus();
    textField.setSelectionRange(textField.value.length, textField.value.length);
  });
}

export default class MoodyImageCaption extends Plugin {
  init() {
    const editor = this.editor;

    editor.ui.componentFactory.add('moodyImageCaption', () => {
      const button = new ButtonView();

      button.set({
        label: 'Image Caption',
        icon,
        tooltip: true,
      });

      button.bind('isEnabled').to(editor, 'isReadOnly', isReadOnly => !isReadOnly);

      button.on('execute', async () => {
        const insertionSelection = getSelectionSnapshot(editor);
        const values = await openImageCaptionDialog(DEFAULT_VALUES);

        if (!values) {
          return;
        }

        const modelFragment = editor.data.parse(buildCaptionHtml(values));

        editor.model.change(writer => {
          if (insertionSelection) {
            editor.model.insertContent(modelFragment, insertionSelection);
            writer.setSelection(insertionSelection);
          }
          else {
            editor.model.insertContent(modelFragment);
          }
        });

        editor.editing.view.focus();
      });

      return button;
    });
  }
}