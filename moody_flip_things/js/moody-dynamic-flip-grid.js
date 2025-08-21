/**
 * JavaScript for Moody Dynamic Flip Grid behavior (Drupal 10+ style).
 */


(function (Drupal) {
  Drupal.behaviors.moody_dynamic_flip_grid = {
    attach(context) {
      document.querySelectorAll('.moody-dynamic-flip-grid-item').forEach((el) => {
        if (once('dynamic-flip-grid', el, context).length) {
          if (typeof jQuery(el).flip === 'function') {
            jQuery(el).flip({
              trigger: 'hover',
            });
          }
        }
      });
    },
  };
})(Drupal);