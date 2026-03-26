(function (Drupal, once) {
  var offsetListenersBound = false;

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

  function updateStickyOffset() {
    document.documentElement.style.setProperty('--moody-showcase-sticky-offset', getStickyOffset() + 'px');
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
})(Drupal, once);
