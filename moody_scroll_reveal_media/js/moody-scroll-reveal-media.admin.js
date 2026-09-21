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
      once('moody-reveal-visual-editor', '[data-reveal-editor]', context).forEach(function (root) {
        var slide = root.parentElement.closest('fieldset');
        var host = root.querySelector('[data-reveal-preview]');
        var device = 'mobile';
        var dimensions = { mobile: [375, 667], tablet: [768, 900], desktop: [1280, 800] };
        var toolbar = document.createElement('div');
        toolbar.className = 'reveal-editor-toolbar';
        var viewport = document.createElement('div');
        viewport.className = 'reveal-editor-viewport';
        var canvas = document.createElement('div');
        canvas.className = 'reveal-editor-canvas';
        var warning = document.createElement('p');
        warning.setAttribute('role', 'status');
        viewport.append(canvas);
        host.append(toolbar, viewport, warning);
        var boxes = {};
        function field(element, key) { return root.querySelector('[data-reveal-field="' + device + ':' + element + ':' + key + '"]'); }
        function value(element, key) { return Number(field(element, key).value); }
        function set(element, key, number) {
          var input = field(element, key);
          input.value = Math.round(clamp(number, Number(input.min), Number(input.max)));
        }
        function update() {
          var enabled = root.querySelector('input[type="checkbox"]').checked;
          var overlay = slide.querySelector('[name$="[title_display]"]').value === 'overlay';
          host.hidden = !enabled || !overlay;
          root.querySelectorAll('[data-reveal-device-fields]').forEach(function (panel) { panel.hidden = !enabled || !overlay || panel.dataset.revealDeviceFields !== device; });
          toolbar.querySelectorAll('button').forEach(function (button) { button.setAttribute('aria-pressed', button.dataset.device === device ? 'true' : 'false'); });
          var size = dimensions[device];
          var scale = Math.min(1, (viewport.clientWidth || 375) / size[0]);
          canvas.style.width = size[0] + 'px';
          canvas.style.height = size[1] + 'px';
          canvas.style.transform = 'scale(' + scale + ')';
          viewport.style.height = size[1] * scale + 'px';
          var mediaImage = slide.querySelector('.media-library-item img, .media-library-widget img');
          canvas.style.backgroundImage = mediaImage ? 'linear-gradient(#0005, #0005), url(' + JSON.stringify(mediaImage.src) + ')' : '';
          var title = slide.querySelector('[name$="[title]"]');
          var body = slide.querySelector('[name$="[body][value]"]');
          var rich = slide.querySelector('.ck-editor__editable');
          var plain = document.createElement('div');
          // Parse as text only; never inject editor HTML into the preview.
          plain.textContent = rich ? rich.textContent : (body ? body.value.replace(/<[^>]*>/g, '') : '');
          var clipped = false;
          Object.keys(boxes).forEach(function (element) {
            var box = boxes[element];
            box.querySelector('span').textContent = (element === 'title' ? title.value : plain.textContent) || (element === 'title' ? Drupal.t('Heading') : Drupal.t('Subheading'));
            set(element, 'width', Math.min(value(element, 'width'), 100 - value(element, 'x')));
            Object.assign(box.style, {left: value(element, 'x') + '%', top: value(element, 'y') + '%', width: value(element, 'width') + '%', fontSize: value(element, 'size') + 'px', textAlign: field(element, 'align').value});
            clipped = clipped || box.offsetTop + box.offsetHeight > size[1];
          });
          warning.textContent = clipped ? Drupal.t('Text extends below the preview. Move it up, widen it, or reduce its size; check the actual page before publishing.') : Drupal.t('Drag text to move; drag its right edge to resize. Arrow keys move text; Shift + arrows move faster. Numeric controls below are also available.');
        }
        Object.keys(dimensions).forEach(function (name) {
          var button = document.createElement('button');
          button.type = 'button'; button.dataset.device = name;
          button.textContent = Drupal.t(name.charAt(0).toUpperCase() + name.slice(1));
          button.addEventListener('click', function () { device = name; update(); });
          toolbar.append(button);
        });
        ['title', 'body'].forEach(function (element) {
          var box = document.createElement('div');
          box.className = 'reveal-editor-box reveal-editor-box--' + element;
          box.tabIndex = 0;
          box.setAttribute('role', 'group');
          box.setAttribute('aria-label', Drupal.t(element === 'title' ? 'Move heading' : 'Move subheading'));
          box.append(document.createElement('span'));
          var handle = document.createElement('button');
          handle.type = 'button'; handle.className = 'reveal-editor-resize';
          handle.setAttribute('aria-label', Drupal.t('Resize text width (left and right arrow keys)'));
          box.append(handle); canvas.append(box); boxes[element] = box;
          box.addEventListener('pointerdown', function (event) {
            if (event.button !== 0) return;
            event.preventDefault();
            var resize = event.target === handle;
            var start = {x: value(element, 'x'), y: value(element, 'y'), width: value(element, 'width')};
            var rect = canvas.getBoundingClientRect();
            var target = resize ? handle : box;
            target.focus(); target.setPointerCapture(event.pointerId);
            function move(next) {
              var dx = (next.clientX - event.clientX) / rect.width * 100;
              var dy = (next.clientY - event.clientY) / rect.height * 100;
              if (resize) set(element, 'width', Math.min(start.width + dx, 100 - start.x));
              else { set(element, 'x', Math.min(start.x + dx, 100 - start.width)); set(element, 'y', start.y + dy); }
              update();
            }
            function finish() { target.removeEventListener('pointermove', move); target.removeEventListener('pointerup', finish); target.removeEventListener('pointercancel', cancel); }
            function cancel() { Object.keys(start).forEach(function (key) { set(element, key, start[key]); }); update(); finish(); }
            target.addEventListener('pointermove', move);
            target.addEventListener('pointerup', finish);
            target.addEventListener('pointercancel', cancel);
          });
          box.addEventListener('keydown', function (event) {
            if (!/^Arrow/.test(event.key)) return;
            event.preventDefault();
            var step = event.shiftKey ? 5 : 1;
            var key = event.target === handle ? 'width' : (/Left|Right/.test(event.key) ? 'x' : 'y');
            set(element, key, value(element, key) + (/Left|Up/.test(event.key) ? -step : step));
            update();
          });
        });
        slide.addEventListener('input', update);
        slide.addEventListener('change', update);
        root.addEventListener('toggle', update);
        var observer = new ResizeObserver(update);
        observer.observe(viewport);
        root._revealCleanup = function () { observer.disconnect(); slide.removeEventListener('input', update); slide.removeEventListener('change', update); };
        update();
      });
    },
    detach: function (context, settings, trigger) {
      if (trigger === 'unload') once.remove('moody-reveal-visual-editor', '[data-reveal-editor]', context).forEach(function (root) { if (root._revealCleanup) root._revealCleanup(); });
    }
  };
})(Drupal, once);
