(function (Drupal, once) {
  function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
  }

  function toPercent(value) {
    return Math.round(clamp(value, 0, 100) * 100) / 100;
  }

  function updatePicker(picker, x, y) {
    var marker = picker.querySelector('.moody-image-gallery-focus-preview__marker');
    var fieldset = picker.closest('fieldset');
    var xInput = fieldset ? fieldset.querySelector('.moody-image-gallery-focus-input--x') : null;
    var yInput = fieldset ? fieldset.querySelector('.moody-image-gallery-focus-input--y') : null;
    var xValue = toPercent(x) + '%';
    var yValue = toPercent(y) + '%';

    picker.dataset.focusX = xValue;
    picker.dataset.focusY = yValue;
    picker.style.backgroundPosition = xValue + ' ' + yValue;
    picker.setAttribute('aria-valuenow', String(Math.round(toPercent(x))));
    picker.setAttribute('aria-valuetext', 'Horizontal ' + Math.round(toPercent(x)) + ' percent, vertical ' + Math.round(toPercent(y)) + ' percent');

    if (marker) {
      marker.style.left = xValue;
      marker.style.top = yValue;
    }

    if (xInput) {
      xInput.value = xValue;
      xInput.dispatchEvent(new Event('input', { bubbles: true }));
      xInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    if (yInput) {
      yInput.value = yValue;
      yInput.dispatchEvent(new Event('input', { bubbles: true }));
      yInput.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  function pointFromEvent(picker, event) {
    var rect = picker.getBoundingClientRect();
    var x = ((event.clientX - rect.left) / rect.width) * 100;
    var y = ((event.clientY - rect.top) / rect.height) * 100;

    return {
      x: clamp(x, 0, 100),
      y: clamp(y, 0, 100)
    };
  }

  function getSelectedPreviewUrl(fieldset) {
    var image = fieldset ? fieldset.querySelector('.js-media-library-selection img') : null;

    if (!image) {
      return '';
    }

    return image.currentSrc || image.getAttribute('src') || '';
  }

  function syncPickerImage(picker) {
    var fieldset = picker.closest('fieldset');
    var previewUrl = getSelectedPreviewUrl(fieldset);

    if (!previewUrl) {
      picker.style.backgroundImage = 'none';
      return;
    }

    picker.style.backgroundImage = 'url("' + previewUrl.replace(/"/g, '\\"') + '")';
  }

  function bindPicker(picker) {
    var fieldset = picker.closest('fieldset');
    var startX = parseFloat(picker.dataset.focusX || '50');
    var startY = parseFloat(picker.dataset.focusY || '50');

    updatePicker(picker, isNaN(startX) ? 50 : startX, isNaN(startY) ? 50 : startY);
    syncPickerImage(picker);

    if (fieldset && typeof MutationObserver === 'function') {
      (new MutationObserver(function () {
        syncPickerImage(picker);
      })).observe(fieldset, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['src']
      });
    }

    picker.addEventListener('pointerdown', function (event) {
      var point;

      event.preventDefault();
      picker.focus();
      picker.setPointerCapture(event.pointerId);
      point = pointFromEvent(picker, event);
      updatePicker(picker, point.x, point.y);
    });

    picker.addEventListener('pointermove', function (event) {
      var point;

      if (!picker.hasPointerCapture(event.pointerId)) {
        return;
      }

      point = pointFromEvent(picker, event);
      updatePicker(picker, point.x, point.y);
    });

    picker.addEventListener('pointerup', function (event) {
      if (picker.hasPointerCapture(event.pointerId)) {
        picker.releasePointerCapture(event.pointerId);
      }
    });

    picker.addEventListener('pointercancel', function (event) {
      if (picker.hasPointerCapture(event.pointerId)) {
        picker.releasePointerCapture(event.pointerId);
      }
    });

    picker.addEventListener('keydown', function (event) {
      var step = event.shiftKey ? 10 : 2;
      var currentX = parseFloat(picker.dataset.focusX || '50');
      var currentY = parseFloat(picker.dataset.focusY || '50');

      if (event.key === 'ArrowLeft') {
        event.preventDefault();
        updatePicker(picker, currentX - step, currentY);
      }
      else if (event.key === 'ArrowRight') {
        event.preventDefault();
        updatePicker(picker, currentX + step, currentY);
      }
      else if (event.key === 'ArrowUp') {
        event.preventDefault();
        updatePicker(picker, currentX, currentY - step);
      }
      else if (event.key === 'ArrowDown') {
        event.preventDefault();
        updatePicker(picker, currentX, currentY + step);
      }
      else if (event.key === 'Home') {
        event.preventDefault();
        updatePicker(picker, 0, 0);
      }
      else if (event.key === 'End') {
        event.preventDefault();
        updatePicker(picker, 100, 100);
      }
    });
  }

  Drupal.behaviors.moodyImageGalleryAdmin = {
    attach: function (context) {
      once('moody-image-gallery-focus-picker', '[data-focus-picker]', context).forEach(bindPicker);
    }
  };
})(Drupal, once);