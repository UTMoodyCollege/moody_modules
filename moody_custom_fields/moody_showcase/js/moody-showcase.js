(function (Drupal, once) {
  'use strict';

  var MIN_PINNED_HEIGHT = 320;
  var offsetListenersBound = false;
  var scrollRefreshScheduled = false;

  function getViewportHeight() {
    return window.innerHeight || document.documentElement.clientHeight || MIN_PINNED_HEIGHT;
  }

  function getStickyOffset() {
    var selectors = [
      '#toolbar-administration',
      '#toolbar-bar',
      '.toolbar-tray-horizontal.is-active',
      '#brandbar',
      'body > header',
      'header[role="banner"]'
    ];
    var offset = 32;

    selectors.forEach(function (selector) {
      var element = document.querySelector(selector);
      var rect;
      var styles;

      if (!element) {
        return;
      }

      styles = window.getComputedStyle(element);
      if (styles.position !== 'fixed' && styles.position !== 'sticky') {
        return;
      }

      rect = element.getBoundingClientRect();
      if (rect.height <= 0 || rect.bottom <= 0 || rect.top > getViewportHeight()) {
        return;
      }

      offset = Math.max(offset, Math.round(rect.bottom + 32));
    });

    return offset;
  }

  function getPinnedTopOffset(stickyOffset) {
    return stickyOffset;
  }

  function updateStickyOffset() {
    var stickyOffset = getStickyOffset();
    var pinnedTopOffset = getPinnedTopOffset(stickyOffset);
    var pinnedHeight = Math.max(getViewportHeight() - pinnedTopOffset, MIN_PINNED_HEIGHT);

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
