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
        $('.moody-carousel', context).each(function(el) {
          var id = $(this).attr('id');
          var carouselOptions = drupalSettings['moody_carousel'][id];
          var slickOptions = {
            dots: Boolean( parseInt(carouselOptions['dots'])),
            fade: Boolean( parseInt(carouselOptions['fade'])),
            slidesToScroll: parseInt(carouselOptions['slidesToScroll']),
            slidesToShow: parseInt(carouselOptions['slidesToShow']),
            // 600px
            // 900px
            // 1200px
            // 1600px
            // Each breakpoint in Texas Design System should be accounted for.
            responsive: [
              {
                breakpoint: 1600,
                settings: {
                  slidesToShow: 3,
                  slidesToScroll: 3,
                  infinite: true,
                  dots: true
                }
              },
              {
                breakpoint: 1200,
                settings: {
                  slidesToShow: 1,
                  slidesToScroll: 1
                }
              },
              {
                breakpoint: 900,
                settings: {
                  slidesToShow: 1,
                  slidesToScroll: 1
                }
              },
              {
                breakpoint: 600,
                settings: {
                  slidesToShow: 1,
                  slidesToScroll: 1
                }
              }
              // You can unslick at a given breakpoint now by adding:
              // settings: "unslick"
              // instead of a settings object
            ]
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
