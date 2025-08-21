/**
 * JavaScript for Moody Dynamic Flip Grid behavior.
 */

(function ($, Drupal, drupalSettings) {
    Drupal.behaviors.moody_dynamic_flip_grid = {
      attach: function (context, settings) {
        $(".moody-dynamic-flip-grid-item").once('dynamic-flip-grid').each(function() {
          $(this).flip({
            trigger: "hover",
          });
        });
      },
    };
  })(jQuery, Drupal, drupalSettings);