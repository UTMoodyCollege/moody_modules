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
    return 0;
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

      showcases.forEach(function (showcase) {
        var mediaInner = showcase.querySelector('.showcase-media-inner--reveal');

        if (!mediaInner) {
          return;
        }

        mediaInner.style.transform = 'none';
      });
    }
  };
})(Drupal, once);
