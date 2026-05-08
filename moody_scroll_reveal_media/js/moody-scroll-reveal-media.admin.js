(function (Drupal, once) {
  var ROWS = ['top', 'center', 'bottom'];
  var COLUMNS = ['left', 'center', 'right'];

  function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
  }

  function splitPosition(value) {
    if (!value || value === 'center') {
      return { x: 'center', y: 'center' };
    }

    var parts = value.split('-');

    return {
      y: parts[0] || 'center',
      x: parts[1] || 'center'
    };
  }

  function combinePosition(row, column) {
    if (row === 'center' && column === 'center') {
      return 'center';
    }

    return row + '-' + column;
  }

  function updatePicker(picker, position) {
    var fieldset = picker.closest('fieldset');
    var xInput = fieldset ? fieldset.querySelector('.moody-scroll-reveal-media-position-input--x') : null;
    var yInput = fieldset ? fieldset.querySelector('.moody-scroll-reveal-media-position-input--y') : null;
    var sample = picker.querySelector('[data-text-position-sample]');
    var parts = splitPosition(position);

    picker.dataset.textPositionValue = position;
    picker.setAttribute('aria-activedescendant', 'moody-scroll-reveal-media-position-option-' + position);

    if (sample) {
      sample.dataset.position = position;
    }

    Array.prototype.forEach.call(picker.querySelectorAll('[data-text-position-option]'), function (option) {
      var isActive = option.getAttribute('data-text-position-option') === position;
      option.classList.toggle('is-active', isActive);
      option.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    if (xInput) {
      xInput.value = parts.x;
      xInput.dispatchEvent(new Event('input', { bubbles: true }));
      xInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    if (yInput) {
      yInput.value = parts.y;
      yInput.dispatchEvent(new Event('input', { bubbles: true }));
      yInput.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  function syncDisplayState(picker) {
    var fieldset = picker.closest('fieldset');
    var displayField = fieldset ? fieldset.querySelector('[name$="[title_display]"]') : null;
    var enabled = !displayField || displayField.value === 'overlay';

    picker.classList.toggle('is-disabled', !enabled);
    picker.setAttribute('aria-disabled', enabled ? 'false' : 'true');
  }

  function getPositionFromPoint(picker, event) {
    var rect = picker.getBoundingClientRect();
    var row = clamp(Math.floor(((event.clientY - rect.top) / rect.height) * 3), 0, 2);
    var column = clamp(Math.floor(((event.clientX - rect.left) / rect.width) * 3), 0, 2);

    return combinePosition(ROWS[row], COLUMNS[column]);
  }

  function movePosition(picker, key) {
    var current = splitPosition(picker.dataset.textPositionValue || 'center');
    var row = ROWS.indexOf(current.y);
    var column = COLUMNS.indexOf(current.x);

    if (key === 'ArrowLeft') {
      column = clamp(column - 1, 0, 2);
    }
    else if (key === 'ArrowRight') {
      column = clamp(column + 1, 0, 2);
    }
    else if (key === 'ArrowUp') {
      row = clamp(row - 1, 0, 2);
    }
    else if (key === 'ArrowDown') {
      row = clamp(row + 1, 0, 2);
    }
    else if (key === 'Home') {
      row = 0;
      column = 0;
    }
    else if (key === 'End') {
      row = 2;
      column = 2;
    }

    updatePicker(picker, combinePosition(ROWS[row], COLUMNS[column]));
  }

  function bindPicker(picker) {
    var initial = picker.dataset.textPositionValue || 'center';
    var fieldset = picker.closest('fieldset');
    var displayField = fieldset ? fieldset.querySelector('[name$="[title_display]"]') : null;

    picker.addEventListener('pointerdown', function (event) {
      event.preventDefault();
      updatePicker(picker, getPositionFromPoint(picker, event));
      picker.focus();
    });

    picker.addEventListener('keydown', function (event) {
      if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].indexOf(event.key) === -1) {
        return;
      }

      event.preventDefault();
      movePosition(picker, event.key);
    });

    Array.prototype.forEach.call(picker.querySelectorAll('[data-text-position-option]'), function (option) {
      option.addEventListener('click', function (event) {
        event.preventDefault();
        updatePicker(picker, option.getAttribute('data-text-position-option'));
        picker.focus();
      });
    });

    if (displayField) {
      displayField.addEventListener('change', function () {
        syncDisplayState(picker);
      });
    }

    updatePicker(picker, initial);
    syncDisplayState(picker);
  }

  Drupal.behaviors.moodyScrollRevealMediaAdmin = {
    attach: function (context) {
      once('moody-scroll-reveal-media-position-picker', '[data-text-position-picker]', context).forEach(bindPicker);
    }
  };
})(Drupal, once);