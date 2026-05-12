import { Plugin } from 'ckeditor5/src/core';
import { ButtonView } from 'ckeditor5/src/ui';
import icon from '../../../../icons/moody-readable-text.svg';

const DEFAULT_VALUES = {
  preset: 'balanced',
  measure: 'default',
  gutter: 'medium',
};

const PRESETS = {
  balanced: {
    measure: 'default',
    gutter: 'medium',
    summary: 'Balanced reading width with comfortable desktop side padding.',
  },
  narrow: {
    measure: 'narrow',
    gutter: 'large',
    summary: 'Tighter article-style measure with generous desktop padding.',
  },
  wide: {
    measure: 'wide',
    gutter: 'small',
    summary: 'Wider feature-style block with light desktop padding.',
  },
};

const MEASURE_LABELS = {
  narrow: 'Narrow measure',
  default: 'Standard measure',
  wide: 'Wide measure',
};

const GUTTER_LABELS = {
  none: 'No extra desktop padding',
  small: 'Small desktop padding',
  medium: 'Medium desktop padding',
  large: 'Large desktop padding',
};

function getSelectionSnapshot(editor) {
  try {
    return editor.model.createSelection(editor.model.document.selection);
  }
  catch (error) {
    return null;
  }
}

function getSelectedHtml(editor, selection) {
  const { model, data } = editor;

  if (!selection || selection.isCollapsed || selection.rangeCount === 0) {
    return '';
  }

  try {
    return data.stringify(model.getSelectedContent(selection)).trim();
  }
  catch (error) {
    return '';
  }
}

function buildReadableTextHtml(content, values) {
  const classes = [
    'moody-readable-text',
    `moody-readable-text--measure-${values.measure}`,
    `moody-readable-text--pad-${values.gutter}`,
  ];

  return `<div class="${classes.join(' ')}">${content || '<p>Add your readable text here.</p>'}</div>`;
}

function getPresetFromValues(measure, gutter) {
  return Object.entries(PRESETS).find(([, preset]) => {
    return preset.measure === measure && preset.gutter === gutter;
  })?.[0] || 'custom';
}

function getPreviewText(values) {
  const preset = PRESETS[values.preset];

  if (preset) {
    return preset.summary;
  }

  return `${MEASURE_LABELS[values.measure]} with ${GUTTER_LABELS[values.gutter].toLowerCase()}.`;
}

function openReadableTextDialog(initialValues) {
  return new Promise(resolve => {
    const dialog = document.createElement('dialog');
    dialog.className = 'moody-readable-text-dialog';
    dialog.innerHTML = `
      <form class="moody-readable-text-dialog__form" method="dialog">
        <div class="moody-readable-text-dialog__header">
          <h2 class="moody-readable-text-dialog__title">Readable Text Block</h2>
          <p class="moody-readable-text-dialog__description">Create a narrower text block for long-form reading. Desktop padding and width apply only on larger screens.</p>
        </div>
        <div class="moody-readable-text-dialog__grid">
          <label class="moody-readable-text-dialog__field">
            <span class="moody-readable-text-dialog__label">Preset</span>
            <select class="moody-readable-text-dialog__select" name="preset">
              <option value="balanced">Balanced</option>
              <option value="narrow">Narrow Article</option>
              <option value="wide">Wide Feature</option>
              <option value="custom">Custom</option>
            </select>
          </label>
          <label class="moody-readable-text-dialog__field">
            <span class="moody-readable-text-dialog__label">Reading width</span>
            <select class="moody-readable-text-dialog__select" name="measure">
              <option value="narrow">Narrow</option>
              <option value="default">Standard</option>
              <option value="wide">Wide</option>
            </select>
          </label>
          <label class="moody-readable-text-dialog__field">
            <span class="moody-readable-text-dialog__label">Desktop side padding</span>
            <select class="moody-readable-text-dialog__select" name="gutter">
              <option value="none">None</option>
              <option value="small">Small</option>
              <option value="medium">Medium</option>
              <option value="large">Large</option>
            </select>
          </label>
        </div>
        <p class="moody-readable-text-dialog__hint">Balanced is the default. Move to Custom only if you want to tune width and padding separately.</p>
        <p class="moody-readable-text-dialog__preview" data-role="preview"></p>
        <div class="moody-readable-text-dialog__actions">
          <button class="moody-readable-text-dialog__button moody-readable-text-dialog__button--secondary" type="button" value="cancel">Cancel</button>
          <button class="moody-readable-text-dialog__button moody-readable-text-dialog__button--primary" type="submit" value="apply">Apply</button>
        </div>
      </form>
    `;

    document.body.appendChild(dialog);

    const presetField = dialog.querySelector('[name="preset"]');
    const measureField = dialog.querySelector('[name="measure"]');
    const gutterField = dialog.querySelector('[name="gutter"]');
    const preview = dialog.querySelector('[data-role="preview"]');
    const cancelButton = dialog.querySelector('[value="cancel"]');

    const values = {
      preset: initialValues.preset,
      measure: initialValues.measure,
      gutter: initialValues.gutter,
    };

    const syncFields = () => {
      presetField.value = values.preset;
      measureField.value = values.measure;
      gutterField.value = values.gutter;
      preview.textContent = getPreviewText(values);
    };

    const applyPreset = presetName => {
      values.preset = presetName;
      if (PRESETS[presetName]) {
        values.measure = PRESETS[presetName].measure;
        values.gutter = PRESETS[presetName].gutter;
      }
      syncFields();
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

    presetField.addEventListener('change', () => {
      applyPreset(presetField.value);
    });

    measureField.addEventListener('change', () => {
      values.measure = measureField.value;
      values.preset = getPresetFromValues(values.measure, values.gutter);
      syncFields();
    });

    gutterField.addEventListener('change', () => {
      values.gutter = gutterField.value;
      values.preset = getPresetFromValues(values.measure, values.gutter);
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
          preset: values.preset,
          measure: values.measure,
          gutter: values.gutter,
        });
      }
      else {
        cleanup(null);
      }
    }, { once: true });

    syncFields();
    dialog.showModal();
  });
}

export default class MoodyReadableText extends Plugin {
  init() {
    const editor = this.editor;

    editor.ui.componentFactory.add('moodyReadableText', () => {
      const button = new ButtonView();

      button.set({
        label: 'Readable Text Block',
        icon,
        tooltip: true,
      });

      button.bind('isEnabled').to(editor, 'isReadOnly', isReadOnly => !isReadOnly);

      button.on('execute', async () => {
        const insertionSelection = getSelectionSnapshot(editor);
        const selectedHtml = getSelectedHtml(editor, insertionSelection);
        const values = await openReadableTextDialog(DEFAULT_VALUES);

        if (!values) {
          return;
        }

        const html = buildReadableTextHtml(selectedHtml, values);
        const modelFragment = editor.data.parse(html);

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