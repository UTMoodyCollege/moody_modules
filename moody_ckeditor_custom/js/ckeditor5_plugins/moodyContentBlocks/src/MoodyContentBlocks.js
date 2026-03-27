import { Plugin } from 'ckeditor5/src/core';
import { ButtonView } from 'ckeditor5/src/ui';
import icon from '../../../../icons/moody-content-blocks.svg';

export default class MoodyContentBlocks extends Plugin {
  init() {
    const editor = this.editor;

    const buttonFactory = function () {
      const button = new ButtonView();

      button.set(
        {
          label: 'Moody Content Blocks',
          icon: icon,
          tooltip: true,
        }
      );

      // Change the model using the model writer.
      const write = writer => {
        // Insert the text at the user's current position.
        editor.model.insertContent(writer.createText('It works!'));
      }
      const executeHandler = () => {
       editor.model.change(write);
      }
      button.on('execute', executeHandler);

      return button;
    };

    editor.ui.componentFactory.add('moodyContentBlocks', buttonFactory);
  }
}
