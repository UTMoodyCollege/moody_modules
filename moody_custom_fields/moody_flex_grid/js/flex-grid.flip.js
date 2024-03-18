// A behavior for our drupal moody_flex_grid_flip behavior that will implement similar to:
// jQuery(document).ready(function($) {
//     $('.flip-container').flip({
//         trigger: 'hover' // or 'click' if you want the flip effect to occur on click
//     });
// });

(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.moody_flex_grid_flip = {
    attach: function (context, settings) {
      $(".flip-container").flip({
        trigger: "hover",
      });

      // Adjust the height of the flip containers
      $(".flip-container").each(function () {
        var frontHeight = $(this).find(".front").outerHeight();
        var backHeight = $(this).find(".back").outerHeight();
        var maxHeight = Math.max(frontHeight, backHeight);

        $(this).css('min-height', maxHeight + 'px'); // Use min-height to enforce the minimum
      });

      // Optionally, adjust height on flip if needed
      $('.flip-container').on('flip:done', function() {
          var frontHeight = $(this).find('.front').outerHeight();
          var backHeight = $(this).find('.back').outerHeight();
          var maxHeight = Math.max(frontHeight, backHeight);
      
          $(this).css('min-height', maxHeight + 'px'); // Use min-height to enforce the minimum
        });
    },
  };
})(jQuery, Drupal, drupalSettings);
