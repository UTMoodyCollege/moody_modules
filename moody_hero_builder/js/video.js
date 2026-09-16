/** Decorative embeds: no SDK dependency, no third-party request in poster mode. */
(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.moodyHeroVideo = {
    attach(context) {
      once('moody-hero-video', '[data-hero-video]', context).forEach((media) => {
        const toggle = media.querySelector('.mhb-video-toggle');
        const url = new URL(media.dataset.heroVideo);
        if (!toggle || !['https://player.vimeo.com', 'https://www.youtube-nocookie.com'].includes(url.origin)) return;
        const reduced = matchMedia('(prefers-reduced-motion: reduce)');
        let frame = null;
        const fit = () => {
          if (!frame) return;
          const width = Math.max(media.clientWidth, media.clientHeight * 16 / 9);
          frame.style.width = `${width}px`;
          frame.style.height = `${width * 9 / 16}px`;
        };
        const stop = () => {
          // ponytail: unloading guarantees stop without SDK races; resume restarts.
          frame?.remove(); frame = null;
          toggle.textContent = Drupal.t('Play background video');
          toggle.setAttribute('aria-pressed', 'false');
        };
        const play = () => {
          if (frame) return;
          frame = document.createElement('iframe');
          frame.src = url.href;
          frame.title = Drupal.t('Decorative background video');
          frame.tabIndex = -1;
          frame.setAttribute('aria-hidden', 'true');
          frame.allow = 'autoplay';
          frame.referrerPolicy = 'strict-origin-when-cross-origin';
          media.append(frame); fit();
          toggle.textContent = Drupal.t('Pause background video');
          toggle.setAttribute('aria-pressed', 'true');
        };
        toggle.hidden = false;
        toggle.addEventListener('click', () => frame ? stop() : play());
        const visibility = () => { if (document.hidden) stop(); };
        const preference = () => { if (reduced.matches) stop(); };
        reduced.addEventListener('change', preference);
        document.addEventListener('visibilitychange', visibility);
        const resize = new ResizeObserver(fit); resize.observe(media);
        stop();
        if (!reduced.matches && !navigator.connection?.saveData && !document.hidden && matchMedia('(min-width: 900px)').matches) play();
        media._heroVideoCleanup = () => { stop(); resize.disconnect(); reduced.removeEventListener('change', preference); document.removeEventListener('visibilitychange', visibility); };
      });
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload') once.remove('moody-hero-video', '[data-hero-video]', context).forEach((media) => media._heroVideoCleanup?.());
    },
  };
})(Drupal, once);
