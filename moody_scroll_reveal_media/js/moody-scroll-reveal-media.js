(function (Drupal, once) {
  function getRevealOffset(direction) {
    switch (direction) {
      case 'top':
        return { xPercent: 0, yPercent: -16 };

      case 'bottom':
        return { xPercent: 0, yPercent: 16 };

      case 'left':
        return { xPercent: -16, yPercent: 0 };

      case 'right':
      default:
        return { xPercent: 16, yPercent: 0 };
    }
  }

  Drupal.behaviors.moodyScrollRevealMedia = {
    attach: function (context) {
      var blocks = once('moody-scroll-reveal-media', '.moody-scroll-reveal-media', context);

      if (!blocks.length || !window.gsap || !window.ScrollTrigger) {
        return;
      }

      window.gsap.registerPlugin(window.ScrollTrigger);

      blocks.forEach(function (block) {
        var viewport = block.querySelector('[data-scroll-reveal-media]');
        var slides = block.querySelectorAll('.moody-scroll-reveal-media__slide');

        if (!viewport || slides.length < 2) {
          return;
        }

        block.classList.add('is-enhanced');

        window.gsap.set(slides, {
          autoAlpha: 0,
          pointerEvents: 'none',
          xPercent: 0,
          yPercent: 0,
          zIndex: 0
        });

        window.gsap.set(slides[0], {
          autoAlpha: 1,
          pointerEvents: 'auto',
          zIndex: 1
        });

        Array.prototype.slice.call(slides, 1).forEach(function (slide, index) {
          var offset = getRevealOffset(slide.getAttribute('data-reveal-direction'));

          window.gsap.set(slide, {
            autoAlpha: 0,
            xPercent: offset.xPercent,
            yPercent: offset.yPercent,
            zIndex: index + 2
          });
        });

        var timeline = window.gsap.timeline({
          defaults: {
            duration: 1,
            ease: 'none'
          },
          scrollTrigger: {
            trigger: block,
            start: 'top top',
            end: function () {
              return '+=' + ((slides.length - 1) * window.innerHeight);
            },
            scrub: 0.4,
            pin: viewport,
            anticipatePin: 1,
            invalidateOnRefresh: true
          }
        });

        Array.prototype.slice.call(slides, 1).forEach(function (slide, index) {
          var previous = slides[index];
          var offset = getRevealOffset(slide.getAttribute('data-reveal-direction'));

          timeline.to(previous, {
            autoAlpha: 0.18,
            scale: 0.985
          }, index);

          timeline.fromTo(slide, {
            autoAlpha: 0,
            xPercent: offset.xPercent,
            yPercent: offset.yPercent
          }, {
            autoAlpha: 1,
            xPercent: 0,
            yPercent: 0,
            pointerEvents: 'auto'
          }, index);
        });
      });
    }
  };
})(Drupal, once);
