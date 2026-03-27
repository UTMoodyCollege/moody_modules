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
        let shortcode = '[nice_letter lead="A"]s your text here[/nice_letter]';

        if (selectedText) {
          const lead = selectedText.charAt(0);
          const remainder = selectedText.slice(1).trim() || ' your text here';
          shortcode = `[nice_letter lead="${lead}"]${remainder}[/nice_letter]`;

          editor.model.change((writer) => {
            editor.model.deleteContent(editor.model.document.selection);
            editor.model.insertContent(writer.createText(shortcode));
          });
          return;
        }

        editor.model.change((writer) => {
          editor.model.insertContent(writer.createText(shortcode));
        });
      });

      return button;
    });
  }
}
