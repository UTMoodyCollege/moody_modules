(function (Drupal, once) {
  function isFadeAnimation(animationStyle) {
    return animationStyle !== 'slide';
  }

  function getRevealOffset(direction, animationStyle) {
    var distance = isFadeAnimation(animationStyle) ? 16 : 104;

    switch (direction) {
      case 'top':
        return { xPercent: 0, yPercent: -distance };

      case 'bottom':
        return { xPercent: 0, yPercent: distance };

      case 'left':
        return { xPercent: -distance, yPercent: 0 };

      case 'right':
      default:
        return { xPercent: distance, yPercent: 0 };
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
        var animationStyle = block.getAttribute('data-animation-style') || 'fade';
        var fadeAnimation = isFadeAnimation(animationStyle);

        if (!viewport || slides.length < 2) {
          return;
        }

        block.classList.add('is-enhanced');
        console.log('[moody-scroll-reveal-media] animation mode:', animationStyle, 'block:', block.id || '(no id)');

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
          var offset = getRevealOffset(slide.getAttribute('data-reveal-direction'), animationStyle);

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
            trigger: viewport,
            start: 'top top',
            end: function () {
              return '+=' + ((slides.length - 1) * viewport.offsetHeight);
            },
            scrub: 0.4,
            pin: true,
            anticipatePin: 1,
            invalidateOnRefresh: true
          }
        });

        Array.prototype.slice.call(slides, 1).forEach(function (slide, index) {
          var previous = slides[index];
          var offset = getRevealOffset(slide.getAttribute('data-reveal-direction'), animationStyle);

          if (fadeAnimation) {
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
            return;
          }

          timeline.set(slide, {
            autoAlpha: 1,
            pointerEvents: 'auto'
          }, index);

          timeline.to(slide, {
            xPercent: 0,
            yPercent: 0
          }, index);

          timeline.set(previous, {
            autoAlpha: 0,
            pointerEvents: 'none'
          }, index + 1);
        });
      });
    }
  };
})(Drupal, once);
