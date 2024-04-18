// A behavior for our drupal moody_flex_grid_flip behavior that will implement similar to:
// jQuery(document).ready(function($) {
//     $('.flip-container').flip({
//         trigger: 'hover' // or 'click' if you want the flip effect to occur on click
//     });
// });

(function ($, Drupal, drupalSettings) {
    Drupal.behaviors.moody_flip_things_image_grid = {
      attach: function (context, settings) {
        $(".moody-flip-things-image-grid").flip({
          trigger: "hover",
        });
  
      },
    };
  })(jQuery, Drupal, drupalSettings);
  