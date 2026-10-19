(function (Drupal, once) {
  Drupal.behaviors.MoodyAmbientVideoFixedScroll = {
    attach: function (context) {
      var blocks = once('moody-ambient-video-fixed-scroll', '.moody-ambient-video-fixed-scroll', context);
      if (!blocks.length) {
        return;
      }

      if (!window.gsap || !window.ScrollTrigger) {
        return;
      }

      if (typeof window.gsap.registerPlugin === 'function') {
        window.gsap.registerPlugin(window.ScrollTrigger);
      }

      blocks.forEach(function (block) {
        var overflowContainer = block.querySelector('#hidden-overflow-container');
        var videoWrapper = block.querySelector('#video-wrapper');
        var videoEl = videoWrapper ? videoWrapper.querySelector('#moody-video') : null;

        if (!overflowContainer || !videoWrapper || !videoEl) {
          return;
        }

        // Match existing CSS behavior: videos are only shown >= 900px.
        var mm = window.gsap.matchMedia();

        mm.add('(min-width: 900px) and (prefers-reduced-motion: no-preference)', function () {
          // Keep the wrapper in its crop window; pinning it exposes the block background.
          var isShortMode = block.classList.contains('moody-ambient-video-short');
          var driftPercent = isShortMode ? 8 : 10;
          var scale = 1 + (driftPercent * 0.02);

          var tween = window.gsap.fromTo(
            videoEl,
            { yPercent: -driftPercent, scale: scale },
            {
              yPercent: driftPercent,
              scale: scale,
              ease: 'none',
              scrollTrigger: {
                trigger: overflowContainer,
                start: 'top bottom',
                end: 'bottom top',
                scrub: true,
                invalidateOnRefresh: true
              }
            }
          );

          // Ensure ScrollTrigger recalculates after the ambient video resizes itself.
          var refreshTimer = window.setTimeout(function () {
            if (window.ScrollTrigger && typeof window.ScrollTrigger.refresh === 'function') {
              window.ScrollTrigger.refresh();
            }
          }, 600);

          return function () {
            window.clearTimeout(refreshTimer);
            if (tween && typeof tween.kill === 'function') {
              tween.kill();
            }
          };
        });
      });
    }
  };
})(Drupal, once);
