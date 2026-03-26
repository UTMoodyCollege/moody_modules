(function (Drupal, once) {
  var offsetListenersBound = false;
  var scrollRefreshScheduled = false;

  function getStickyOffset() {
    var brandbar = document.getElementById('brandbar');
    var header = document.querySelector('header');
    var offset = 32;

    if (brandbar) {
      offset += brandbar.offsetHeight;
    }

    if (header) {
      offset += header.offsetHeight;
    }

    return offset;
  }

  function getPinnedTopOffset() {
    var brandbar = document.getElementById('brandbar');
    var header = document.querySelector('header');
    var offset = 0;

    if (brandbar) {
      offset += brandbar.offsetHeight;
    }

    if (header) {
      offset += header.offsetHeight;
    }

    return offset;
  }

  function updateStickyOffset() {
    var stickyOffset = getStickyOffset();
    var pinnedTopOffset = getPinnedTopOffset();
    var pinnedHeight = Math.max(window.innerHeight - pinnedTopOffset, 320);

    document.documentElement.style.setProperty('--moody-showcase-sticky-offset', stickyOffset + 'px');
    document.documentElement.style.setProperty('--moody-showcase-pinned-top-offset', pinnedTopOffset + 'px');
    document.documentElement.style.setProperty('--moody-showcase-pinned-height', pinnedHeight + 'px');
    if (window.ScrollTrigger && typeof window.ScrollTrigger.refresh === 'function' && !scrollRefreshScheduled) {
      scrollRefreshScheduled = true;
      window.setTimeout(function () {
        window.ScrollTrigger.refresh();
        scrollRefreshScheduled = false;
      }, 50);
    }
  }

  Drupal.behaviors.moodyShowcaseStickyMedia = {
    attach: function (context) {
      var showcases = once('moody-showcase-sticky-media', '.moody-showcase--sticky-media', context);
      if (!showcases.length) {
        return;
      }

      updateStickyOffset();

      if (!offsetListenersBound) {
        window.addEventListener('resize', updateStickyOffset);
        window.addEventListener('load', updateStickyOffset);
        offsetListenersBound = true;
      }
    }
  };

  Drupal.behaviors.moodyShowcasePinnedReveal = {
    attach: function (context) {
      var showcases = once('moody-showcase-pinned-reveal', '.moody-showcase--pinned-reveal', context);
      if (!showcases.length) {
        return;
      }

      if (!window.gsap || !window.ScrollTrigger) {
        return;
      }

      if (typeof window.gsap.registerPlugin === 'function') {
        window.gsap.registerPlugin(window.ScrollTrigger);
      }

      showcases.forEach(function (showcase) {
        var mediaTarget = showcase.querySelector('.showcase-media--pinned-trigger');
        var mediaFrame = showcase.querySelector('.showcase-media-frame--pinned');
        var mediaInner = showcase.querySelector('.showcase-media-inner--reveal');
        var text = showcase.querySelector('.showcase-text');

        if (!mediaTarget || !mediaFrame || !mediaInner || !text) {
          return;
        }

        var mm = window.gsap.matchMedia();

        mm.add('(min-width: 900px)', function () {
          var pinnedTopOffset = function () {
            return getPinnedTopOffset();
          };

          var startOffset = function () {
            return 'top top+=' + pinnedTopOffset();
          };

          var endOffset = function () {
            return 'bottom top+=' + pinnedTopOffset();
          };

          var revealTween = window.gsap.fromTo(
            mediaInner,
            {
              yPercent: -10,
              scale: 1.12
            },
            {
              yPercent: 6,
              scale: 1,
              ease: 'none',
              scrollTrigger: {
                trigger: showcase,
                start: startOffset,
                endTrigger: text,
                end: endOffset,
                scrub: 0.35,
                invalidateOnRefresh: true
              }
            }
          );

          window.setTimeout(updateStickyOffset, 100);

          return function () {
            if (revealTween && typeof revealTween.kill === 'function') {
              revealTween.kill();
            }
          };
        });
      });
    }
  };
})(Drupal, once);
