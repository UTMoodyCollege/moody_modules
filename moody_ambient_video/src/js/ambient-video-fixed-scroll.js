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

        mm.add('(min-width: 900px)', function () {
          // “Fixed within a window” effect:
          // - pin the videoWrapper only while the hero is in view
          // - scrub a parallax drift on the <video> inside it
          // Short mode is a tighter crop, so use slightly less drift and a small scale to prevent edge reveal.
          var isShortMode = block.classList.contains('moody-ambient-video-short');
          var driftPercent = isShortMode ? 8 : 10;
          var scale = isShortMode ? (1 + (driftPercent * 0.02)) : 1;

          var headerOffset = function () {
            var brandbar = document.getElementById('brandbar');
            var header = document.querySelector('header');
            var brandbarHeight = brandbar ? brandbar.clientHeight : 0;
            var headerHeight = header ? header.clientHeight : 0;
            return brandbarHeight + headerHeight;
          };

          // Pin the wrapper using transforms so it remains clipped by the overflow container.
          var pinTrigger = window.ScrollTrigger.create({
            trigger: overflowContainer,
            start: function () {
              return 'top top+=' + headerOffset();
            },
            end: function () {
              return 'bottom top+=' + headerOffset();
            },
            pin: videoWrapper,
            pinSpacing: false,
            pinType: 'transform',
            anticipatePin: 1,
            invalidateOnRefresh: true
          });

          var tween = window.gsap.fromTo(
            videoEl,
            { yPercent: -driftPercent, scale: scale },
            {
              yPercent: driftPercent,
              scale: scale,
              ease: 'none',
              scrollTrigger: {
                trigger: overflowContainer,
                start: function () {
                  return 'top top+=' + headerOffset();
                },
                end: function () {
                  return 'bottom top+=' + headerOffset();
                },
                scrub: true,
                invalidateOnRefresh: true
              }
            }
          );

          // Ensure ScrollTrigger recalculates after the ambient video resizes itself.
          window.setTimeout(function () {
            if (window.ScrollTrigger && typeof window.ScrollTrigger.refresh === 'function') {
              window.ScrollTrigger.refresh();
            }
          }, 600);

          return function () {
            if (tween && typeof tween.kill === 'function') {
              tween.kill();
            }
            if (pinTrigger && typeof pinTrigger.kill === 'function') {
              pinTrigger.kill();
            }
          };
        });
      });
    }
  };
})(Drupal, once);
