/**
 * @file
 * Provides Slick loader.
 */

 (function ($, Drupal, drupalSettings) {

    'use strict';

    /**
     * Attaches slick behavior to HTML element identified by CSS selector .slick.
     *
     * @type {Drupal~behavior}
     */
    Drupal.behaviors.moodyCarousel = {
      attach: function (context) {
        console.log("Moody carousel123");
        $('.moody-carousel', context).each(function(el) {
          var id = $(this).attr('id');
          var carouselOptions = drupalSettings['moody_carousel'][id];
          var slickOptions = {
            dots: Boolean( parseInt(carouselOptions['dots'])),
            fade: Boolean( parseInt(carouselOptions['fade'])),
            slidesToScroll: parseInt(carouselOptions['slidesToScroll']),
            slidesToShow: parseInt(carouselOptions['slidesToShow']),
          }
          if (carouselOptions['autoplay'] != "0") {
            slickOptions.autoplay = carouselOptions['autoplay'];
            slickOptions.autoplaySpeed = parseFloat(carouselOptions['autoplaySpeed']) * 1000;
          }
          $(this).slick(slickOptions);
        });


      }
    };

  })(jQuery, Drupal, drupalSettings);
