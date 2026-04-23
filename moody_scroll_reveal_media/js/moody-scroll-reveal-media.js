(function (Drupal, once) {
  function raf(callback) {
    if (typeof window.requestAnimationFrame === 'function') {
      window.requestAnimationFrame(callback);
      return;
    }

    window.setTimeout(callback, 16);
  }

  function isFadeAnimation(animationStyle) {
    return animationStyle !== 'slide';
  }

  function refreshScrollTrigger() {
    if (window.ScrollTrigger && typeof window.ScrollTrigger.refresh === 'function') {
      window.ScrollTrigger.refresh();
    }
  }

  function scheduleRefresh() {
    raf(function () {
      raf(refreshScrollTrigger);
    });
  }

  function whenMediaReady(block, callback) {
    var media = block.querySelectorAll('img, video, iframe');
    var pending = media.length;
    var hasFinished = false;

    if (!pending) {
      callback();
      return;
    }

    function finish() {
      if (hasFinished) {
        return;
      }

      hasFinished = true;
      callback();
    }

    function markReady() {
      pending -= 1;
      if (pending <= 0) {
        finish();
      }
    }

    Array.prototype.forEach.call(media, function (element) {
      if (element.tagName === 'IMG') {
        if (element.complete && element.naturalWidth > 0) {
          markReady();
          return;
        }

        element.addEventListener('load', markReady, { once: true });
        element.addEventListener('error', markReady, { once: true });
        return;
      }

      if (element.tagName === 'VIDEO') {
        if (element.readyState >= 1) {
          markReady();
          return;
        }

        element.addEventListener('loadedmetadata', markReady, { once: true });
        element.addEventListener('error', markReady, { once: true });
        return;
      }

      element.addEventListener('load', markReady, { once: true });
      element.addEventListener('error', markReady, { once: true });
    });

    window.setTimeout(finish, 1200);
  }

  function bindMediaRefresh(block) {
    var media = block.querySelectorAll('img, video, iframe');

    Array.prototype.forEach.call(media, function (element) {
      var eventName = 'load';

      if (element.tagName === 'VIDEO') {
        eventName = 'loadedmetadata';
      }

      element.addEventListener(eventName, scheduleRefresh, { once: true });
      element.addEventListener('error', scheduleRefresh, { once: true });
    });
  }

  function postToVimeo(iframe, method) {
    if (!iframe || !iframe.contentWindow) {
      return;
    }

    iframe.contentWindow.postMessage(JSON.stringify({ method: method }), '*');
  }

  function syncSlideMedia(slides, activeIndex) {
    Array.prototype.forEach.call(slides, function (slide, index) {
      var iframe = slide.querySelector('[data-vimeo-player]');
      var video = slide.querySelector('[data-html5-video]');

      if (iframe && iframe.getAttribute('data-video-autoplay') === 'true') {
        if (index === activeIndex) {
          postToVimeo(iframe, 'play');
        }
        else {
          postToVimeo(iframe, 'pause');
        }
      }

      if (video && video.getAttribute('data-video-autoplay') === 'true') {
        if (index === activeIndex) {
          video.play().catch(function () {});
        }
        else {
          video.pause();
        }
      }
    });
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
        whenMediaReady(block, function () {
          var activeSlideIndex = 0;

          block.classList.add('is-enhanced');
          bindMediaRefresh(block);

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

          syncSlideMedia(slides, activeSlideIndex);

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
              invalidateOnRefresh: true,
              onUpdate: function (self) {
                var nextActiveIndex = Math.round(self.progress * (slides.length - 1));

                if (nextActiveIndex === activeSlideIndex) {
                  return;
                }

                activeSlideIndex = nextActiveIndex;
                syncSlideMedia(slides, activeSlideIndex);
              }
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

          scheduleRefresh();
        });
      });
    }
  };
})(Drupal, once);
