(function (Drupal, once) {
  function parseItems(container) {
    var script = container.querySelector('[data-gallery-items]');
    if (!script) {
      return [];
    }

    try {
      return JSON.parse(script.textContent);
    }
    catch (error) {
      return [];
    }
  }

  function trapFocus(dialog, event) {
    if (event.key !== 'Tab') {
      return;
    }

    var focusable = dialog.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    if (!focusable.length) {
      return;
    }

    var first = focusable[0];
    var last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    }
    else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  Drupal.behaviors.moodyImageGallery = {
    attach: function (context) {
      var galleries = once('moody-image-gallery', '.moody-image-gallery', context);
      galleries.forEach(function (gallery) {
        var dialogWrapper = gallery.querySelector('[data-gallery-dialog]');
        var dialog = gallery.querySelector('.moody-image-gallery__dialog');
        var image = gallery.querySelector('[data-gallery-image]');
        var caption = gallery.querySelector('[data-gallery-caption]');
        var counter = gallery.querySelector('[data-gallery-counter]');
        var triggers = gallery.querySelectorAll('[data-gallery-trigger]');
        var prevButton = gallery.querySelector('[data-gallery-prev]');
        var nextButton = gallery.querySelector('[data-gallery-next]');
        var closeButtons = gallery.querySelectorAll('[data-gallery-close]');
        var items = parseItems(gallery);
        var activeIndex = 0;
        var previousActive = null;

        if (!dialogWrapper || !dialog || !image || !items.length) {
          return;
        }

        function render(index) {
          var item = items[index];
          activeIndex = index;
          image.src = item.src;
          image.alt = item.alt || '';
          caption.textContent = item.caption || item.alt || '';
          counter.textContent = (index + 1) + ' / ' + items.length;
        }

        function open(index) {
          previousActive = document.activeElement;
          render(index);
          dialogWrapper.hidden = false;
          document.body.classList.add('moody-image-gallery-open');
          document.body.style.overflow = 'hidden';
          window.requestAnimationFrame(function () {
            dialog.focus();
          });
        }

        function close() {
          dialogWrapper.hidden = true;
          document.body.classList.remove('moody-image-gallery-open');
          document.body.style.overflow = '';
          if (previousActive && typeof previousActive.focus === 'function') {
            previousActive.focus();
          }
        }

        function next() {
          render((activeIndex + 1) % items.length);
        }

        function prev() {
          render((activeIndex - 1 + items.length) % items.length);
        }

        triggers.forEach(function (trigger) {
          trigger.addEventListener('click', function () {
            open(parseInt(trigger.getAttribute('data-gallery-index'), 10) || 0);
          });
        });

        closeButtons.forEach(function (button) {
          button.addEventListener('click', close);
        });

        if (prevButton) {
          prevButton.addEventListener('click', prev);
        }

        if (nextButton) {
          nextButton.addEventListener('click', next);
        }

        dialogWrapper.addEventListener('keydown', function (event) {
          if (event.key === 'Escape') {
            event.preventDefault();
            close();
          }
          else if (event.key === 'ArrowRight') {
            event.preventDefault();
            next();
          }
          else if (event.key === 'ArrowLeft') {
            event.preventDefault();
            prev();
          }

          trapFocus(dialog, event);
        });
      });
    }
  };
})(Drupal, once);
