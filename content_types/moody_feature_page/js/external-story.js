(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.moodyExternalStory = {
    attach(context) {
      once('moody-external-story', '[data-moody-external-story][data-auto-redirect]', context).forEach((notice) => {
        const controls = notice.querySelector('[data-redirect-controls]');
        const stop = notice.querySelector('[data-stop-redirect]');
        const status = notice.querySelector('[data-redirect-status]');
        controls.hidden = false;
        const timer = window.setTimeout(() => {
          window.location.replace(notice.querySelector('[data-story-link]').href);
        }, 20000);
        const cancel = () => {
          window.clearTimeout(timer);
          status.textContent = Drupal.t('Automatic forwarding stopped. Use “Read the story” whenever you are ready.');
          stop.disabled = true;
        };
        stop.addEventListener('click', cancel);
        // Do not navigate a background tab or restart the timer after Back.
        window.addEventListener('pagehide', cancel, { once: true });
        document.addEventListener('visibilitychange', () => {
          if (document.hidden) cancel();
        });
        if (document.hidden) cancel();
      });
    },
  };
})(Drupal, once);
