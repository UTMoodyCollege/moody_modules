(function (Drupal, once) {
  Drupal.behaviors.moodyBlockCloneLauncher = {
    attach(context, settings) {
      const chooseUrl = settings.moodyBlockClone && settings.moodyBlockClone.chooseUrl;
      if (!chooseUrl) {
        return;
      }

      once('moody-block-clone-launcher', '.inline-block-create-button', context).forEach((createButton) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'moody-block-clone__launcher';

        const link = document.createElement('a');
        link.href = chooseUrl;
        link.textContent = 'Clone block from other page';
        link.className = 'use-ajax moody-block-clone__launcher-link';
        link.setAttribute('data-dialog-type', 'dialog');
        link.setAttribute('data-dialog-renderer', 'off_canvas');

        wrapper.appendChild(link);
        createButton.insertAdjacentElement('afterend', wrapper);
        Drupal.attachBehaviors(wrapper, settings);
      });
    }
  };
})(Drupal, once);
