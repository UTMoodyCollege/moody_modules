(function (Drupal, once) {
  'use strict';

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
   * Activates a single focal point (marker + caption) and deactivates others.
   */
  function activateFocalPoint(markers, captions, index) {
    Array.prototype.forEach.call(markers, function (marker, i) {
      if (i === index) {
        marker.classList.add('is-active');
      } else {
        marker.classList.remove('is-active');
      }
    });

    Array.prototype.forEach.call(captions, function (caption, i) {
      if (i === index) {
        caption.classList.add('is-active');
      } else {
        caption.classList.remove('is-active');
      }
    });
  }

  /**
   * Initializes a single focal-point block.
   */
  function initBlock(block) {
    var stage = block.querySelector('[data-focal-point-stage]');
    var captionsWrapper = block.querySelector('[data-focal-captions]');
    var markers = block.querySelectorAll('[data-focal-point-stage] [data-focal-index]');
    var captions = block.querySelectorAll('[data-focal-captions] [data-focal-index]');
    var count = captions.length;

    if (!stage || !captionsWrapper || count < 1 || !window.gsap || !window.ScrollTrigger) {
      return;
    }

    window.gsap.registerPlugin(window.ScrollTrigger);

    whenImagesReady(block, function () {
      block.classList.add('is-enhanced');

      // Initial state: show only the first caption; others are hidden.
      window.gsap.set(captions, { autoAlpha: 0, yPercent: 8, pointerEvents: 'none' });
      window.gsap.set(captions[0], { autoAlpha: 1, yPercent: 0, pointerEvents: 'auto' });
      activateFocalPoint(markers, captions, 0);

      var activeIndex = 0;

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
            return '+=' + ((count - 1) * block.offsetHeight);
          },
          scrub: 0.5,
          pin: true,
          anticipatePin: 1,
          invalidateOnRefresh: true,
          onUpdate: function (self) {
            var nextIndex = Math.min(
              count - 1,
              Math.round(self.progress * (count - 1))
            );
            if (nextIndex !== activeIndex) {
              activeIndex = nextIndex;
              activateFocalPoint(markers, captions, activeIndex);
            }
          },
        },
      });

      // Build caption cross-fade transitions.
      for (var i = 1; i < count; i++) {
        var prev = captions[i - 1];
        var curr = captions[i];

        // Fade out the previous caption.
        timeline.to(prev, { autoAlpha: 0, yPercent: -8, pointerEvents: 'none' }, i - 1);

        // Fade in the current caption from below.
        timeline.fromTo(
          curr,
          { autoAlpha: 0, yPercent: 8 },
          { autoAlpha: 1, yPercent: 0, pointerEvents: 'auto' },
          i - 1
        );

        // Animate the focal-point marker dot to its new position using a
        // "ghost" element on the stage.  We use a CSS custom-property approach
        // so the marker repositions smoothly without a full GSAP target.
        (function (markerFrom, markerTo) {
          if (!markerFrom || !markerTo) { return; }
          var fromX = parseFloat(markerFrom.style.left) || 50;
          var fromY = parseFloat(markerFrom.style.top) || 50;
          var toX   = parseFloat(markerTo.style.left)   || 50;
          var toY   = parseFloat(markerTo.style.top)    || 50;

          // We animate a proxy object and update the active marker's position
          // manually so the marker visually travels across the image.
          var proxy = { x: fromX, y: fromY };
          timeline.to(proxy, {
            x: toX,
            y: toY,
            ease: 'power2.inOut',
            onUpdate: function () {
              markerTo.style.left = proxy.x + '%';
              markerTo.style.top  = proxy.y + '%';
            },
          }, i - 1);
        }(markers[i - 1], markers[i]));
      }

      scheduleRefresh();
    });
  }

  Drupal.behaviors.moodyFocalPoint = {
    attach: function (context) {
      var blocks = once('moody-focal-point', '.moody-focal-point', context);
      blocks.forEach(initBlock);
    },
  };

})(Drupal, once);
