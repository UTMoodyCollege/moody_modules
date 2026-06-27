(function (Drupal, once) {
  'use strict';

  var MIN_PIN_HEIGHT = 240;

  function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
  }

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

  function getViewportHeight() {
    return window.innerHeight || document.documentElement.clientHeight || MIN_PIN_HEIGHT;
  }

  function getFixedTopOffset() {
    var selectors = [
      '#toolbar-administration',
      '#toolbar-bar',
      '.toolbar-tray-horizontal.is-active',
      '#brandbar',
      'body > header',
      'header[role="banner"]'
    ];
    var offset = 0;

    selectors.forEach(function (selector) {
      var element = document.querySelector(selector);
      var rect;
      var styles;

      if (!element) {
        return;
      }

      styles = window.getComputedStyle(element);
      if (styles.position !== 'fixed' && styles.position !== 'sticky') {
        return;
      }

      rect = element.getBoundingClientRect();
      if (rect.height <= 0 || rect.bottom <= 0 || rect.top > getViewportHeight()) {
        return;
      }

      offset = Math.max(offset, rect.bottom);
    });

    return Math.max(0, Math.round(offset));
  }

  function getAvailablePinHeight() {
    return Math.max(MIN_PIN_HEIGHT, getViewportHeight() - getFixedTopOffset());
  }

  function getPinHeight(viewport) {
    var availableHeight = getAvailablePinHeight();
    var rect = viewport.getBoundingClientRect();
    var measuredHeight = rect.height || viewport.offsetHeight || availableHeight;

    if (measuredHeight < MIN_PIN_HEIGHT || measuredHeight > availableHeight * 1.25) {
      return availableHeight;
    }

    return Math.min(measuredHeight, availableHeight);
  }

  function getScrollDistanceFromPinHeight(pinHeight, slides) {
    return Math.max(0, (slides.length - 1) * pinHeight);
  }

  function setPinHeight(block, viewport) {
    var pinHeight = getPinHeight(viewport);

    block.style.setProperty('--moody-scroll-reveal-media-pin-height', pinHeight + 'px');
    block.style.setProperty('--moody-scroll-reveal-media-pin-top-offset', getFixedTopOffset() + 'px');
    return pinHeight;
  }

  function updateScrollFootprint(block, viewport, slides) {
    var pinHeight = setPinHeight(block, viewport);
    var scrollDistance = getScrollDistanceFromPinHeight(pinHeight, slides);

    block.style.setProperty('--moody-scroll-reveal-media-reserved-height', (pinHeight + scrollDistance) + 'px');
    return scrollDistance;
  }

  function getInitialProgress(block, viewport, slides) {
    var blockRect = block.getBoundingClientRect();
    var start = window.scrollY + blockRect.top - getFixedTopOffset();
    var distance = updateScrollFootprint(block, viewport, slides);

    return distance > 0 ? clamp((window.scrollY - start) / distance, 0, 1) : 0;
  }

  function getInitialSlideIndex(block, viewport, slides) {
    return getActiveSlideIndex(getInitialProgress(block, viewport, slides), slides);
  }

  function isPartiallyVisible(element) {
    var rect = element.getBoundingClientRect();
    var viewportHeight = getViewportHeight();

    return rect.width > 0 && rect.height > 0 && rect.bottom > 0 && rect.top < viewportHeight;
  }

  function markReservedSlide(slides, activeIndex) {
    Array.prototype.forEach.call(slides, function (slide, index) {
      slide.classList.toggle('is-reserved-active', index === activeIndex);
    });
  }

  function reserveScrollFootprint(block, viewport, slides) {
    var initialProgress = getInitialProgress(block, viewport, slides);
    var activeIndex = getActiveSlideIndex(initialProgress, slides);

    block.__moodyScrollRevealRestore = {
      active: isPartiallyVisible(block),
      progress: initialProgress,
      scrollY: window.scrollY
    };
    markReservedSlide(slides, activeIndex);
    block.classList.add('is-reserving');
  }

  function clearReservedState(block, slides) {
    block.classList.remove('is-reserving');
    markReservedSlide(slides, -1);
  }

  function resetBlock(block) {
    var viewport = block.querySelector('[data-scroll-reveal-media]');
    var slides = block.querySelectorAll('.moody-scroll-reveal-media__slide');
    var timeline = block.__moodyScrollRevealMediaTimeline;
    var cleanup = block.__moodyScrollRevealMediaCleanup;
    var scrollTrigger = timeline && timeline.scrollTrigger;

    if (typeof cleanup === 'function') {
      cleanup();
    }

    if (scrollTrigger && typeof scrollTrigger.kill === 'function') {
      scrollTrigger.kill(true);
    }

    if (timeline && typeof timeline.kill === 'function') {
      timeline.kill();
    }

    block.__moodyScrollRevealMediaTimeline = null;
    block.__moodyScrollRevealMediaCleanup = null;
    block.__moodyScrollRevealRestore = null;
    block.classList.remove('is-enhanced');
    clearReservedState(block, slides);
    block.style.removeProperty('--moody-scroll-reveal-media-pin-height');
    block.style.removeProperty('--moody-scroll-reveal-media-pin-top-offset');
    block.style.removeProperty('--moody-scroll-reveal-media-reserved-height');

    if (window.gsap) {
      if (viewport) {
        window.gsap.set(viewport, { clearProps: 'all' });
      }
      window.gsap.set(slides, { clearProps: 'all' });
    }
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

  function getActiveSlideIndex(progress, slides) {
    return clamp(Math.round(progress * (slides.length - 1)), 0, slides.length - 1);
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

      if (!blocks.length || !window.gsap) {
        return;
      }

      if (window.ScrollTrigger) {
        window.gsap.registerPlugin(window.ScrollTrigger);
      }


      blocks.forEach(function (block) {
        var viewport = block.querySelector('[data-scroll-reveal-media]');
        var slides = block.querySelectorAll('.moody-scroll-reveal-media__slide');
        var animationStyle = block.getAttribute('data-animation-style') || 'fade';
        var fadeAnimation = isFadeAnimation(animationStyle);

        if (!viewport || slides.length < 2) {
          return;
        }

        reserveScrollFootprint(block, viewport, slides);

        whenMediaReady(block, function () {
          var activeSlideIndex = getInitialSlideIndex(block, viewport, slides);

          block.classList.add('is-enhanced');
          clearReservedState(block, slides);
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
            paused: true,
            defaults: {
              duration: 1,
              ease: 'none'
            }
          });
          block.__moodyScrollRevealMediaTimeline = timeline;

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

          var updatePending = false;

          function updateTimelineFromScroll() {
            var progress = getInitialProgress(block, viewport, slides);
            var nextActiveIndex = getActiveSlideIndex(progress, slides);

            timeline.progress(progress);

            if (progress <= 0.001) {
              window.gsap.set(slides, {
                autoAlpha: 0,
                pointerEvents: 'none',
                scale: 1
              });
              window.gsap.set(slides[0], {
                autoAlpha: 1,
                pointerEvents: 'auto',
                scale: 1
              });
              nextActiveIndex = 0;
            }
            else if (progress >= 0.999) {
              window.gsap.set(slides, {
                autoAlpha: 0,
                pointerEvents: 'none',
                scale: 1
              });
              window.gsap.set(slides[slides.length - 1], {
                autoAlpha: 1,
                pointerEvents: 'auto',
                scale: 1,
                xPercent: 0,
                yPercent: 0
              });
              nextActiveIndex = slides.length - 1;
            }

            if (nextActiveIndex !== activeSlideIndex) {
              activeSlideIndex = nextActiveIndex;
              syncSlideMedia(slides, activeSlideIndex);
            }
          }

          function requestTimelineUpdate() {
            if (updatePending) {
              return;
            }

            updatePending = true;
            raf(function () {
              updatePending = false;
              updateTimelineFromScroll();
            });
          }

          window.addEventListener('scroll', requestTimelineUpdate, { passive: true });
          window.addEventListener('resize', requestTimelineUpdate);
          window.addEventListener('load', requestTimelineUpdate);

          block.__moodyScrollRevealMediaCleanup = function () {
            window.removeEventListener('scroll', requestTimelineUpdate);
            window.removeEventListener('resize', requestTimelineUpdate);
            window.removeEventListener('load', requestTimelineUpdate);
          };

          raf(updateTimelineFromScroll);
          window.setTimeout(requestTimelineUpdate, 120);
          window.setTimeout(function () {
            requestTimelineUpdate();
            block.__moodyScrollRevealRestore = null;
          }, 360);
        });
      });
    },

    detach: function (context, settings, trigger) {
      if (trigger !== 'unload' && trigger !== 'move') {
        return;
      }

      once.remove('moody-scroll-reveal-media', '.moody-scroll-reveal-media', context).forEach(resetBlock);
    }
  };
})(Drupal, once);
