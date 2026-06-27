(function (Drupal, once) {
  'use strict';

  var MIN_STAGE_HEIGHT = 320;

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

  function getViewportHeight() {
    return window.innerHeight || document.documentElement.clientHeight || MIN_STAGE_HEIGHT;
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

  function getStageHeight(stage) {
    var availableHeight = Math.max(MIN_STAGE_HEIGHT, getViewportHeight() - getFixedTopOffset());
    var rect = stage.getBoundingClientRect();
    var measuredHeight = rect.height || stage.offsetHeight || availableHeight;

    if (measuredHeight < MIN_STAGE_HEIGHT || measuredHeight > availableHeight * 1.25) {
      return availableHeight;
    }

    return Math.min(measuredHeight, availableHeight);
  }

  function updateScrollFootprint(block, stage, count) {
    var stageHeight = getStageHeight(stage);
    var scrollDistance = Math.max(0, count * stageHeight);

    block.style.setProperty('--moody-focal-point-stage-height', stageHeight + 'px');
    block.style.setProperty('--moody-focal-point-sticky-top-offset', getFixedTopOffset() + 'px');
    block.style.setProperty('--moody-focal-point-reserved-height', (stageHeight + scrollDistance) + 'px');
    return scrollDistance;
  }

  function getScrollProgress(block, stage, count) {
    var blockRect = block.getBoundingClientRect();
    var start = window.scrollY + blockRect.top - getFixedTopOffset();
    var distance = updateScrollFootprint(block, stage, count);

    return distance > 0 ? clamp((window.scrollY - start) / distance, 0, 1) : 0;
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
    if (block.__moodyFocalPointInitialized) {
      return;
    }

    var stage = block.querySelector('[data-focal-point-stage]');
    var media = block.querySelector('[data-focal-point-media]');
    var image = media && media.querySelector('img');
    var captionsWrapper = block.querySelector('[data-focal-captions]');
    var captions = block.querySelectorAll('[data-focal-captions] [data-focal-index]');
    var count = captions.length;
    var focusPoints = Array.prototype.map.call(captions, readFocusPoint);

    if (!stage || !media || !image || !captionsWrapper || count < 1 || !window.gsap) {
      return;
    }

    whenImagesReady(block, function () {
      if (block.__moodyFocalPointInitialized) {
        return;
      }

      block.__moodyFocalPointInitialized = true;
      block.classList.add('is-enhanced');
      updateScrollFootprint(block, stage, count > 1 ? count : 0);

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
        positionCaption(stage, captions[0], focusPoints[0]);
        applyFocus(stage, media, image, focusPoints[0]);
        return;
      }

      var timeline = window.gsap.timeline({
        paused: true,
        defaults: { duration: 1, ease: 'power1.inOut' },
      });
      block.__moodyFocalPointTimeline = timeline;

      debugLog(block, 'scroll:timeline-created', {
        scrollY: window.scrollY,
        blockRect: block.getBoundingClientRect(),
        stageRect: stage.getBoundingClientRect(),
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

      var updatePending = false;

      function enforceBoundaryState(progress) {
        if (progress <= 0.001) {
          window.gsap.set(captions, { autoAlpha: 0, yPercent: 8, pointerEvents: 'none' });
          window.gsap.set(captions[0], { autoAlpha: 1, yPercent: 0, pointerEvents: 'auto' });
          activeFocus.x = neutralFocus.x;
          activeFocus.y = neutralFocus.y;
          activeFocus.width = neutralFocus.width;
          activeFocus.height = neutralFocus.height;
          applyFocus(stage, media, image, activeFocus);
          return 0;
        }

        if (progress >= 0.999) {
          window.gsap.set(captions, { autoAlpha: 0, yPercent: -8, pointerEvents: 'none' });
          window.gsap.set(captions[count - 1], { autoAlpha: 1, yPercent: 0, pointerEvents: 'auto' });
          activeFocus.x = focusPoints[count - 1].x;
          activeFocus.y = focusPoints[count - 1].y;
          activeFocus.width = focusPoints[count - 1].width;
          activeFocus.height = focusPoints[count - 1].height;
          applyFocus(stage, media, image, activeFocus);
          return count - 1;
        }

        return null;
      }

      function updateTimelineFromScroll() {
        var progress = getScrollProgress(block, stage, count);
        var nextIndex;
        var boundaryIndex;

        Array.prototype.forEach.call(captions, function (caption, index) {
          positionCaption(stage, caption, focusPoints[index]);
        });

        timeline.progress(progress);
        boundaryIndex = enforceBoundaryState(progress);
        nextIndex = boundaryIndex !== null ? boundaryIndex : Math.min(
          count - 1,
          Math.floor(progress * scrollSegments)
        );

        debugLog(block, 'scroll:update', {
          progress: progress,
          nextIndex: nextIndex,
          activeIndex: activeIndex,
          scrollY: window.scrollY,
          blockRect: block.getBoundingClientRect(),
          stageRect: stage.getBoundingClientRect(),
        });

        if (nextIndex !== activeIndex) {
          activeIndex = nextIndex;
          activateFocalPoint(captions, activeIndex);
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

      block.__moodyFocalPointCleanup = function () {
        window.removeEventListener('scroll', requestTimelineUpdate);
        window.removeEventListener('resize', requestTimelineUpdate);
        window.removeEventListener('load', requestTimelineUpdate);
      };

      raf(updateTimelineFromScroll);
      window.setTimeout(requestTimelineUpdate, 120);
      window.setTimeout(requestTimelineUpdate, 360);
    });
  }

  Drupal.behaviors.moodyFocalPoint = {
    attach: function (context) {
      var blocks = once('moody-focal-point', '.moody-focal-point', context);
      blocks.forEach(initBlock);
    },

    detach: function (context, settings, trigger) {
      if (trigger !== 'unload' && trigger !== 'move') {
        return;
      }

      once.remove('moody-focal-point', '.moody-focal-point', context).forEach(function (block) {
        var cleanup = block.__moodyFocalPointCleanup;
        var timeline = block.__moodyFocalPointTimeline;
        var stage = block.querySelector('[data-focal-point-stage]');
        var media = block.querySelector('[data-focal-point-media]');
        var captions = block.querySelectorAll('[data-focal-captions] [data-focal-index]');

        if (typeof cleanup === 'function') {
          cleanup();
        }

        if (timeline && typeof timeline.kill === 'function') {
          timeline.kill();
        }

        block.__moodyFocalPointCleanup = null;
        block.__moodyFocalPointTimeline = null;
        block.__moodyFocalPointInitialized = false;
        block.classList.remove('is-enhanced');
        block.style.removeProperty('--moody-focal-point-stage-height');
        block.style.removeProperty('--moody-focal-point-sticky-top-offset');
        block.style.removeProperty('--moody-focal-point-reserved-height');

        if (window.gsap) {
          window.gsap.set(captions, { clearProps: 'all' });
        }

        if (media) {
          media.style.removeProperty('height');
          media.style.removeProperty('left');
          media.style.removeProperty('top');
          media.style.removeProperty('transform');
          media.style.removeProperty('width');
        }

        if (stage) {
          stage.style.removeProperty('height');
        }
      });
    },
  };

})(Drupal, once);
