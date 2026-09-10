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
        link.textContent = Drupal.t('Clone blocks from other page');
        link.className = 'use-ajax moody-block-clone__launcher-link';
        link.setAttribute('data-dialog-type', 'dialog');
        link.setAttribute('data-dialog-renderer', 'off_canvas');
        link.setAttribute('data-dialog-options', JSON.stringify({ width: 560, dialogClass: 'moody-block-clone-dialog' }));

        wrapper.appendChild(link);
        createButton.insertAdjacentElement('afterend', wrapper);
        Drupal.attachBehaviors(wrapper, settings);
      });
    }
  };

  Drupal.behaviors.moodyBlockCloneSelection = {
    attach(context) {
      const results = context.matches?.('.moody-block-clone__results')
        ? [context]
        : context.querySelectorAll('.moody-block-clone__results');
      once('moody-block-clone-selection', results).forEach((result) => {
        const update = () => {
          let count = 0;
          result.querySelectorAll('.moody-block-clone__select').forEach((checkbox) => {
            checkbox.closest('.moody-block-clone__card').classList.toggle('is-selected', checkbox.checked);
            count += Number(checkbox.checked);
          });
          const status = result.querySelector('.moody-block-clone__selection-count');
          const submit = result.querySelector('.moody-block-clone__copy');
          if (status && submit) {
            status.textContent = Drupal.formatPlural(count, '1 block selected', '@count blocks selected');
            submit.disabled = count === 0;
          }
        };
        result.addEventListener('change', update);
        update();
      });
    }
  };
})(Drupal, once);
