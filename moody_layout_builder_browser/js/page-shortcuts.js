(function (Drupal, once) {
  Drupal.behaviors.moodyPageShortcuts = {
    attach(context) {
      once('moody-page-title', '.moody-page-shortcut--title', context).forEach((details) => {
        const input = details.querySelector('input[type="text"]');
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'button moody-page-shortcut__cancel';
        cancel.textContent = Drupal.t('Cancel');
        const close = () => {
          input.value = input.defaultValue;
          details.open = false;
          details.querySelector('summary').focus();
        };
        cancel.addEventListener('click', close);
        details.querySelector('.moody-page-title-editor').append(cancel);
        details.addEventListener('toggle', () => { if (details.open) input.focus(); });
        input.addEventListener('keydown', event => {
          if (event.key === 'Escape') { event.preventDefault(); close(); }
          if (event.key === 'Enter') {
            event.preventDefault();
            details.querySelector('input[type="submit"]').click();
          }
        });
      });
      once('moody-page-alias', '.moody-page-shortcut--path', context).forEach((details) => {
        const summary = details.querySelector('summary');
        const dialog = document.createElement('dialog');
        dialog.className = 'moody-page-alias-dialog';
        dialog.setAttribute('aria-label', Drupal.t('Edit URL alias'));
        const heading = document.createElement('h2');
        heading.textContent = Drupal.t('URL alias');
        dialog.append(heading);
        Array.from(details.children).filter((child) => child !== summary).forEach((child) => dialog.append(child));
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.textContent = Drupal.t('Cancel');
        cancel.className = 'button';
        cancel.addEventListener('click', () => dialog.close());
        dialog.append(cancel);
        // Remain inside the existing Drupal form, including its CSRF token.
        details.after(dialog);
        summary.setAttribute('aria-haspopup', 'dialog');
        summary.addEventListener('click', (event) => {
          event.preventDefault();
          dialog.showModal();
          dialog.querySelector('input[type="text"]')?.focus();
        });
        dialog.addEventListener('close', () => summary.focus());
        if (dialog.querySelector('.error')) dialog.showModal();
      });
    },
  };
})(Drupal, once);
