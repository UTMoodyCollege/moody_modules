import { Plugin } from 'ckeditor5/src/core';
import { ButtonView } from 'ckeditor5/src/ui';
import icon from '../../../../icons/moody-nice-letter.svg';

function getSelectedPlainText(editor) {
  const selection = editor.model.document.selection;
  const range = selection.getFirstRange();

  if (!range || range.isCollapsed) {
    return '';
  }

  let text = '';
  for (const item of range.getItems()) {
    if (item.is('$textProxy') || item.is('$text')) {
      text += item.data;
    }
  }

  return text.trim();
}

function normalizeColor(value) {
  const normalized = (value || '').trim().toLowerCase();
  const map = {
    '': 'burnt-orange',
    'default': 'burnt-orange',
    'burnt-orange': 'burnt-orange',
    'burnt_orange': 'burnt-orange',
    'orange': 'burnt-orange',
    'black': 'black',
    'charcoal': 'black',
    'dark': 'black',
    'ut': 'ut-gray',
    'ut-gray': 'ut-gray',
    'ut_grey': 'ut-gray',
    'ut-grey': 'ut-gray',
    'ut_gray': 'ut-gray',
    'gray': 'ut-gray',
    'grey': 'ut-gray',
  };

  return map[normalized] || 'burnt-orange';
}

export default class MoodyNiceLetter extends Plugin {
  init() {
    const editor = this.editor;

    editor.ui.componentFactory.add('moodyNiceLetter', () => {
      const button = new ButtonView();

      button.set({
        label: 'Moody Nice Letter',
        icon,
        tooltip: true,
      });

      button.on('execute', () => {
        const selectedText = getSelectedPlainText(editor);
        let lead = 'A';
        let content = 's your text here';

        if (selectedText) {
          lead = selectedText.charAt(0);
          content = selectedText.slice(1).trim() || ' your text here';
        }

        const requestedLead = window.prompt('Lead letter or word', lead);
        if (requestedLead === null) {
          return;
        }

        const requestedColor = window.prompt('Lead color: burnt-orange, black, or ut-gray', 'burnt-orange');
        if (requestedColor === null) {
          return;
        }

        lead = requestedLead.trim() || lead;
        const color = normalizeColor(requestedColor);
        const shortcode = `[nice_letter lead="${lead}" color="${color}"]${content}[/nice_letter]`;

        editor.model.change((writer) => {
          if (selectedText) {
            editor.model.deleteContent(editor.model.document.selection);
          }
          editor.model.insertContent(writer.createText(shortcode));
        });
      });

      return button;
    });
  }
}
