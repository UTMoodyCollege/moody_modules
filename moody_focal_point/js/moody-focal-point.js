(function (Drupal, once) {
  'use strict';

  function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
  }

  /**
   * Schedules two animation frames so layout is fully settled before acting.
   */
  function raf(callback) {
    if (typeof window.requestAnimationFrame === 'function') {
      window.requestAnimationFrame(callback);
      return;
    }
    window.setTimeout(callback, 16);
  }

  function scheduleRefresh() {
    raf(function () {
      raf(function () {
        if (window.ScrollTrigger && typeof window.ScrollTrigger.refresh === 'function') {
          window.ScrollTrigger.refresh();
        }
      });
    });
  }

  function isDebugEnabled() {
    if (typeof window === 'undefined') {
      return false;
    }

    try {
      if (window.localStorage && window.localStorage.getItem('moodyFocalPointDebug') === '1') {
        return true;
      }
    }
    catch (error) {
      // Ignore storage access issues.
    }

    return /(?:\?|&)moodyFocalPointDebug=1(?:&|$)/.test(window.location.search);
  }

  function debugLog(block, eventName, payload) {
    if (!isDebugEnabled() || typeof window === 'undefined' || !window.console || typeof window.console.info !== 'function') {
      return;
    }

    var blockId = block && block.id ? block.id : '(no-id)';
    window.console.info('[moody-focal-point][' + blockId + '] ' + eventName, payload || {});
  }

  function isReadyForViewportInit(element) {
    var rect = element.getBoundingClientRect();
    var viewportHeight = window.innerHeight || document.documentElement.clientHeight;
    var viewportWidth = window.innerWidth || document.documentElement.clientWidth;
    var tolerance = 16;

    if (!rect.width || !rect.height) {
      return false;
    }

    var horizontallyVisible = rect.right > tolerance && rect.left < viewportWidth - tolerance;
    var fullyFitsViewport = rect.top >= -tolerance && rect.bottom <= viewportHeight + tolerance;
    var fillsViewportFrame = rect.top <= tolerance && rect.bottom >= viewportHeight - tolerance;

    return horizontallyVisible && (fullyFitsViewport || fillsViewportFrame);
  }

  function isPartiallyInViewport(element) {
    var rect = element.getBoundingClientRect();
    var viewportHeight = window.innerHeight || document.documentElement.clientHeight;
    var viewportWidth = window.innerWidth || document.documentElement.clientWidth;

    return rect.width > 0
      && rect.height > 0
      && rect.bottom > 0
      && rect.top < viewportHeight
      && rect.right > 0
      && rect.left < viewportWidth;
  }

  function primeBlock(block) {
    if (block.__moodyFocalPointPrimed) {
      return;
    }

    var stage = block.querySelector('[data-focal-point-stage]');
    var media = block.querySelector('[data-focal-point-media]');
    var image = media && media.querySelector('img');
    var captions = block.querySelectorAll('[data-focal-captions] [data-focal-index]');
    var count = captions.length;
    var focusPoints = Array.prototype.map.call(captions, readFocusPoint);

    if (!stage || !media || !image || count < 1) {
      return;
    }

    whenImagesReady(block, function () {
      if (block.__moodyFocalPointPrimed) {
        return;
      }

      block.__moodyFocalPointPrimed = true;
      block.classList.add('is-primed');

      positionCaption(stage, captions[0], focusPoints[0]);
      activateFocalPoint(captions, 0);
      applyFocus(stage, media, image, {
        x: focusPoints[0].x,
        y: focusPoints[0].y,
        width: focusPoints[0].width,
        height: focusPoints[0].height,
      });

      debugLog(block, 'prime:applied', {
        scrollY: window.scrollY,
        stageRect: stage.getBoundingClientRect(),
        firstFocus: focusPoints[0],
      });
    });
  }

  function initWhenFullyVisible(block) {
    if (block.__moodyFocalPointInitialized) {
      return;
    }

    var stage = block.querySelector('[data-focal-point-stage]') || block;

    function start() {
      if (block.__moodyFocalPointInitialized) {
        return;
      }

      stopWatching();

      debugLog(block, 'init:start', {
        scrollY: window.scrollY,
        stageRect: stage.getBoundingClientRect(),
      });
      block.__moodyFocalPointInitialized = true;
      initBlock(block);
    }

    function checkVisibility() {
      var stageRect = stage.getBoundingClientRect();
      var ready = isReadyForViewportInit(stage);
      var partiallyVisible = isPartiallyInViewport(stage);

      debugLog(block, 'visibility:check', {
        ready: ready,
        partiallyVisible: partiallyVisible,
        scrollY: window.scrollY,
        stageRect: stageRect,
      });

      if (partiallyVisible) {
        primeBlock(block);
      }

      if (ready) {
        start();
        return true;
      }

      return false;
    }

    var observer = null;

    function stopWatching() {
      window.removeEventListener('scroll', onScrollOrResize, true);
      window.removeEventListener('resize', onScrollOrResize);

      if (observer) {
        observer.unobserve(stage);
        observer.disconnect();
        observer = null;
      }
    }

    function onScrollOrResize() {
      checkVisibility();
    }

    window.addEventListener('scroll', onScrollOrResize, true);
    window.addEventListener('resize', onScrollOrResize);

    if (typeof window.IntersectionObserver === 'function') {
      observer = new window.IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          debugLog(block, 'visibility:intersection', {
            isIntersecting: entry.isIntersecting,
            intersectionRatio: entry.intersectionRatio,
            boundingClientRect: entry.boundingClientRect,
          });

          if (!entry.isIntersecting) {
            return;
          }

          primeBlock(block);
          checkVisibility();
        });
      }, {
        threshold: [0, 0.25, 0.5, 0.75, 0.95],
      });

      observer.observe(stage);
      checkVisibility();
      return;
    }

    onScrollOrResize();
  }

  /**
   * Waits for all images inside `el` to be loaded before calling `callback`.
   */
  function whenImagesReady(el, callback) {
    var images = el.querySelectorAll('img');
    var pending = images.length;
    var done = false;

    function finish() {
      if (done) { return; }
      done = true;
      callback();
    }

    if (!pending) {
      callback();
      return;
    }

    Array.prototype.forEach.call(images, function (img) {
      if (img.complete && img.naturalWidth > 0) {
        pending -= 1;
        if (pending <= 0) { finish(); }
        return;
      }
      img.addEventListener('load', function () {
        pending -= 1;
        if (pending <= 0) { finish(); }
      }, { once: true });
      img.addEventListener('error', function () {
        pending -= 1;
        if (pending <= 0) { finish(); }
      }, { once: true });
    });

    // Safety timeout so the block still initialises even if an image errors.
    window.setTimeout(finish, 1500);
  }

  /**
   * Activates a single caption and deactivates others.
   */
  function activateFocalPoint(captions, index) {
    Array.prototype.forEach.call(captions, function (caption, i) {
      if (i === index) {
        caption.classList.add('is-active');
      } else {
        caption.classList.remove('is-active');
      }
    });
  }

  function readFocusPoint(element) {
    return {
      x: parseFloat(element.dataset.focalX) || 50,
      y: parseFloat(element.dataset.focalY) || 50,
      width: parseFloat(element.dataset.focalWidth) || 24,
      height: parseFloat(element.dataset.focalHeight) || 24,
      captionX: parseFloat(element.dataset.captionX) || 50,
      captionY: parseFloat(element.dataset.captionY) || 82,
    };
  }

  function positionCaption(stage, caption, focusPoint) {
    var stageRect = stage.getBoundingClientRect();
    var x = (focusPoint.captionX / 100) * stageRect.width;
    var y = (focusPoint.captionY / 100) * stageRect.height;
    var maxWidth = Math.min(448, stageRect.width - 24);

    caption.style.width = maxWidth + 'px';
    caption.style.maxWidth = maxWidth + 'px';

    var captionRect = caption.getBoundingClientRect();
    var halfWidth = captionRect.width / 2;
    var halfHeight = captionRect.height / 2;
    var left = clamp(x, 12 + halfWidth, stageRect.width - 12 - halfWidth);
    var top = clamp(y, 12 + halfHeight, stageRect.height - 12 - halfHeight);

    caption.style.left = left + 'px';
    caption.style.top = top + 'px';
  }

  function setMediaBounds(stage, media, image) {
    var stageRect = stage.getBoundingClientRect();
    var naturalWidth = image.naturalWidth || stageRect.width;
    var naturalHeight = image.naturalHeight || stageRect.height;
    var scale = Math.max(stageRect.width / naturalWidth, stageRect.height / naturalHeight);
    var width = naturalWidth * scale;
    var height = naturalHeight * scale;
    var left = (stageRect.width - width) / 2;
    var top = (stageRect.height - height) / 2;

    media.style.width = width + 'px';
    media.style.height = height + 'px';
    media.style.left = left + 'px';
    media.style.top = top + 'px';

    return {
      stageWidth: stageRect.width,
      stageHeight: stageRect.height,
      mediaWidth: width,
      mediaHeight: height,
      mediaLeft: left,
      mediaTop: top,
    };
  }

  function applyFocus(stage, media, image, focusState) {
    var metrics = setMediaBounds(stage, media, image);
    var boxWidth = metrics.mediaWidth * (focusState.width / 100);
    var boxHeight = metrics.mediaHeight * (focusState.height / 100);
    var centerX = metrics.mediaWidth * (focusState.x / 100);
    var centerY = metrics.mediaHeight * (focusState.y / 100);
    var scale = clamp(
      Math.min(metrics.stageWidth / boxWidth, metrics.stageHeight / boxHeight),
      1,
      8
    );
    var translateX = (metrics.stageWidth / 2) - (metrics.mediaLeft + (centerX * scale));
    var translateY = (metrics.stageHeight / 2) - (metrics.mediaTop + (centerY * scale));
    var minTranslateX = metrics.stageWidth - metrics.mediaLeft - (metrics.mediaWidth * scale);
    var maxTranslateX = -metrics.mediaLeft;
    var minTranslateY = metrics.stageHeight - metrics.mediaTop - (metrics.mediaHeight * scale);
    var maxTranslateY = -metrics.mediaTop;

    if (metrics.mediaWidth * scale <= metrics.stageWidth) {
      translateX = (metrics.stageWidth - (metrics.mediaWidth * scale)) / 2 - metrics.mediaLeft;
    } else {
      translateX = clamp(translateX, minTranslateX, maxTranslateX);
    }

    if (metrics.mediaHeight * scale <= metrics.stageHeight) {
      translateY = (metrics.stageHeight - (metrics.mediaHeight * scale)) / 2 - metrics.mediaTop;
    } else {
      translateY = clamp(translateY, minTranslateY, maxTranslateY);
    }

    media.style.transform = 'translate(' + translateX + 'px, ' + translateY + 'px) scale(' + scale + ')';
  }

  /**
   * Initializes a single focal-point block.
   */
  function initBlock(block) {
    var stage = block.querySelector('[data-focal-point-stage]');
    var media = block.querySelector('[data-focal-point-media]');
    var image = media && media.querySelector('img');
    var captionsWrapper = block.querySelector('[data-focal-captions]');
    var captions = block.querySelectorAll('[data-focal-captions] [data-focal-index]');
    var count = captions.length;
    var focusPoints = Array.prototype.map.call(captions, readFocusPoint);

    if (!stage || !media || !image || !captionsWrapper || count < 1 || !window.gsap || !window.ScrollTrigger) {
      return;
    }

    window.gsap.registerPlugin(window.ScrollTrigger);

    whenImagesReady(block, function () {
      block.classList.add('is-enhanced');

      debugLog(block, 'init:images-ready', {
        count: count,
        scrollY: window.scrollY,
        blockRect: block.getBoundingClientRect(),
        stageRect: stage.getBoundingClientRect(),
        focusPoints: focusPoints,
      });

      Array.prototype.forEach.call(captions, function (caption, index) {
        positionCaption(stage, caption, focusPoints[index]);
      });

      // Initial state: show only the first caption; others are hidden.
      window.gsap.set(captions, { autoAlpha: 0, yPercent: 8, pointerEvents: 'none' });
      window.gsap.set(captions[0], { autoAlpha: 1, yPercent: 0, pointerEvents: 'auto' });
      activateFocalPoint(captions, 0);

      var activeIndex = 0;
      var neutralFocus = {
        x: 50,
        y: 50,
        width: 100,
        height: 100,
      };
      var activeFocus = {
        x: neutralFocus.x,
        y: neutralFocus.y,
        width: neutralFocus.width,
        height: neutralFocus.height,
      };
      var scrollSegments = count;

      applyFocus(stage, media, image, activeFocus);

      if (count === 1) {
        // Single focal point – just show it, no scroll behaviour needed.
        return;
      }

      var timeline = window.gsap.timeline({
        defaults: { duration: 1, ease: 'power1.inOut' },
        scrollTrigger: {
          trigger: block,
          start: 'top top',
          end: function () {
            return '+=' + (scrollSegments * block.offsetHeight);
          },
          scrub: 0.5,
          pin: true,
          anticipatePin: 1,
          invalidateOnRefresh: true,
          onUpdate: function (self) {
            var nextIndex = Math.min(
              count - 1,
              Math.floor(self.progress * scrollSegments)
            );

            debugLog(block, 'scroll:update', {
              progress: self.progress,
              nextIndex: nextIndex,
              activeIndex: activeIndex,
              start: self.start,
              end: self.end,
              scroll: self.scroll(),
              isActive: self.isActive,
            });

            if (nextIndex !== activeIndex) {
              activeIndex = nextIndex;
              activateFocalPoint(captions, activeIndex);
            }
          },
          onRefresh: function () {
            var trigger = timeline.scrollTrigger;

            applyFocus(stage, media, image, activeFocus);
            Array.prototype.forEach.call(captions, function (caption, index) {
              positionCaption(stage, caption, focusPoints[index]);
            });

            debugLog(block, 'scroll:refresh', {
              progress: trigger ? trigger.progress : null,
              start: trigger ? trigger.start : null,
              end: trigger ? trigger.end : null,
              scroll: trigger ? trigger.scroll() : null,
              blockRect: block.getBoundingClientRect(),
              stageRect: stage.getBoundingClientRect(),
            });
          }
        },
      });

      debugLog(block, 'scroll:timeline-created', {
        progress: timeline.scrollTrigger ? timeline.scrollTrigger.progress : null,
        start: timeline.scrollTrigger ? timeline.scrollTrigger.start : null,
        end: timeline.scrollTrigger ? timeline.scrollTrigger.end : null,
        scroll: timeline.scrollTrigger ? timeline.scrollTrigger.scroll() : null,
      });

      timeline.to(activeFocus, {
        x: focusPoints[0].x,
        y: focusPoints[0].y,
        width: focusPoints[0].width,
        height: focusPoints[0].height,
        ease: 'power2.inOut',
        onUpdate: function () {
          applyFocus(stage, media, image, activeFocus);
        },
      }, 0);

      // Build caption cross-fade transitions.
      for (var i = 1; i < count; i++) {
        var prev = captions[i - 1];
        var curr = captions[i];

        // Fade out the previous caption.
        timeline.to(prev, { autoAlpha: 0, yPercent: -8, pointerEvents: 'none' }, i);

        // Fade in the current caption from below.
        timeline.fromTo(
          curr,
          { autoAlpha: 0, yPercent: 8 },
          { autoAlpha: 1, yPercent: 0, pointerEvents: 'auto' },
          i
        );

        (function (fromFocus, toFocus) {
          var proxy = {
            x: fromFocus.x,
            y: fromFocus.y,
            width: fromFocus.width,
            height: fromFocus.height,
          };

          timeline.to(proxy, {
            x: toFocus.x,
            y: toFocus.y,
            width: toFocus.width,
            height: toFocus.height,
            ease: 'power2.inOut',
            onUpdate: function () {
              activeFocus.x = proxy.x;
              activeFocus.y = proxy.y;
              activeFocus.width = proxy.width;
              activeFocus.height = proxy.height;
              applyFocus(stage, media, image, activeFocus);
            },
          }, i);
        }(focusPoints[i - 1], focusPoints[i]));
      }

      scheduleRefresh();
    });
  }

  Drupal.behaviors.moodyFocalPoint = {
    attach: function (context) {
      var blocks = once('moody-focal-point', '.moody-focal-point', context);
      blocks.forEach(initWhenFullyVisible);
    },
  };

})(Drupal, once);
