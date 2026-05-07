(function (Drupal, once) {
  'use strict';

  var IMAGE_SELECTORS = [
    '.js-media-library-selection img',
    '.media-library-item__preview img',
    '.media-library-item img'
  ];

  function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
  }

  function findSelectedImage(root) {
    var selector = IMAGE_SELECTORS.join(', ');
    return root.querySelector(selector);
  }

  function updateImageSource(item, imageUrl, imageAlt) {
    var image = item.querySelector('[data-focal-admin-image]');
    var stage = item.querySelector('[data-focal-admin-stage]');
    if (!image || !stage) {
      return;
    }

    var nextUrl = imageUrl || '';
    var nextAlt = imageAlt || '';

    if ((image.getAttribute('src') || '') === nextUrl && (image.getAttribute('alt') || '') === nextAlt) {
      if (nextUrl) {
        item.classList.remove('is-empty');
      } else {
        item.classList.add('is-empty');
      }
      return;
    }

    if (!nextUrl) {
      image.removeAttribute('src');
      image.alt = '';
      item.classList.add('is-empty');
      stage.dataset.imageUrl = '';
      stage.dataset.imageAlt = '';
      return;
    }

    image.src = nextUrl;
    image.alt = nextAlt;
    stage.dataset.imageUrl = nextUrl;
    stage.dataset.imageAlt = nextAlt;
    item.classList.remove('is-empty');
  }

  function syncFormItems(blockForm) {
    var items = blockForm.querySelectorAll('[data-focal-admin-item]');
    Array.prototype.forEach.call(items, function (item) {
      syncImageFromWidget(blockForm, item);
    });
  }

  function scheduleFormSync(blockForm) {
    if (blockForm.__moodyFocalPointAdminSyncQueued) {
      return;
    }

    blockForm.__moodyFocalPointAdminSyncQueued = true;
    var flush = function () {
      blockForm.__moodyFocalPointAdminSyncQueued = false;
      syncFormItems(blockForm);
    };

    if (typeof window.requestAnimationFrame === 'function') {
      window.requestAnimationFrame(flush);
      return;
    }

    window.setTimeout(flush, 16);
  }

  function ensureFormObserver(blockForm) {
    if (blockForm.__moodyFocalPointAdminObserved) {
      return;
    }

    var mediaWidget = blockForm.querySelector('.js-media-library-widget')
      || blockForm.querySelector('[data-media-library-widget-value]')
      || blockForm;

    blockForm.__moodyFocalPointAdminObserved = true;
    scheduleFormSync(blockForm);

    blockForm.addEventListener('change', function (event) {
      if (mediaWidget === blockForm || mediaWidget.contains(event.target)) {
        scheduleFormSync(blockForm);
      }
    }, true);

    blockForm.addEventListener('input', function (event) {
      if (mediaWidget === blockForm || mediaWidget.contains(event.target)) {
        scheduleFormSync(blockForm);
      }
    }, true);

    if (typeof window.MutationObserver !== 'function') {
      return;
    }

    var observer = new window.MutationObserver(function () {
      scheduleFormSync(blockForm);
    });
    observer.observe(mediaWidget, { childList: true, subtree: true, attributes: true, attributeFilter: ['src', 'value'] });
  }

  function syncImageFromWidget(blockForm, item) {
    var selectedImage = findSelectedImage(blockForm);
    if (selectedImage) {
      updateImageSource(item, selectedImage.currentSrc || selectedImage.src, selectedImage.alt || '');
      return;
    }

    var stage = item.querySelector('[data-focal-admin-stage]');
    if (!stage) {
      return;
    }
    updateImageSource(item, stage.dataset.imageUrl || '', stage.dataset.imageAlt || '');
  }

  function getInputRoot(item) {
    return item.parentElement || item;
  }

  function getInputs(item) {
    var root = getInputRoot(item);

    return {
      x: root.querySelector('[data-focal-input="x"]'),
      y: root.querySelector('[data-focal-input="y"]'),
      width: root.querySelector('[data-focal-input="width"]'),
      height: root.querySelector('[data-focal-input="height"]'),
      captionX: root.querySelector('[data-focal-input="caption-x"]'),
      captionY: root.querySelector('[data-focal-input="caption-y"]')
    };
  }

  function readCaption(inputs) {
    return {
      x: clamp(parseFloat(inputs.captionX && inputs.captionX.value) || 50, 0, 100),
      y: clamp(parseFloat(inputs.captionY && inputs.captionY.value) || 82, 0, 100)
    };
  }

  function readBox(inputs) {
    var width = clamp(parseFloat(inputs.width && inputs.width.value) || 24, 5, 100);
    var height = clamp(parseFloat(inputs.height && inputs.height.value) || 24, 5, 100);
    var x = clamp(parseFloat(inputs.x && inputs.x.value) || 50, width / 2, 100 - (width / 2));
    var y = clamp(parseFloat(inputs.y && inputs.y.value) || 50, height / 2, 100 - (height / 2));

    return { x: x, y: y, width: width, height: height };
  }

  function writeBox(inputs, box) {
    if (inputs.x) {
      inputs.x.value = box.x.toFixed(1);
    }
    if (inputs.y) {
      inputs.y.value = box.y.toFixed(1);
    }
    if (inputs.width) {
      inputs.width.value = box.width.toFixed(1);
    }
    if (inputs.height) {
      inputs.height.value = box.height.toFixed(1);
    }
  }

  function writeCaption(inputs, caption) {
    if (inputs.captionX) {
      inputs.captionX.value = caption.x.toFixed(1);
    }
    if (inputs.captionY) {
      inputs.captionY.value = caption.y.toFixed(1);
    }
  }

  function getDisplayedImageRect(stage, image) {
    var stageRect = stage.getBoundingClientRect();
    var naturalWidth = image.naturalWidth || stageRect.width;
    var naturalHeight = image.naturalHeight || stageRect.height;
    var scale = Math.min(stageRect.width / naturalWidth, stageRect.height / naturalHeight);
    var width = naturalWidth * scale;
    var height = naturalHeight * scale;
    var left = (stageRect.width - width) / 2;
    var top = (stageRect.height - height) / 2;

    return {
      left: left,
      top: top,
      width: width,
      height: height
    };
  }

  function renderBox(stage, image, selection, box) {
    var imageRect = getDisplayedImageRect(stage, image);
    var left = imageRect.left + ((box.x - (box.width / 2)) / 100) * imageRect.width;
    var top = imageRect.top + ((box.y - (box.height / 2)) / 100) * imageRect.height;
    var width = (box.width / 100) * imageRect.width;
    var height = (box.height / 100) * imageRect.height;

    selection.style.left = left + 'px';
    selection.style.top = top + 'px';
    selection.style.width = width + 'px';
    selection.style.height = height + 'px';
  }

  function renderHitArea(stage, image, hitArea) {
    var imageRect = getDisplayedImageRect(stage, image);
    hitArea.style.left = imageRect.left + 'px';
    hitArea.style.top = imageRect.top + 'px';
    hitArea.style.width = imageRect.width + 'px';
    hitArea.style.height = imageRect.height + 'px';
  }

  function renderCaptionPin(stage, pin, caption) {
    pin.style.left = caption.x + '%';
    pin.style.top = caption.y + '%';
  }

  function pointerToPercent(rect, clientX, clientY) {
    return {
      x: clamp(((clientX - rect.left) / rect.width) * 100, 0, 100),
      y: clamp(((clientY - rect.top) / rect.height) * 100, 0, 100)
    };
  }

  function boxFromDrag(start, end) {
    var left = Math.min(start.x, end.x);
    var right = Math.max(start.x, end.x);
    var top = Math.min(start.y, end.y);
    var bottom = Math.max(start.y, end.y);
    var width = clamp(right - left, 5, 100);
    var height = clamp(bottom - top, 5, 100);
    var x = clamp(left + (width / 2), width / 2, 100 - (width / 2));
    var y = clamp(top + (height / 2), height / 2, 100 - (height / 2));

    return { x: x, y: y, width: width, height: height };
  }

  function initItem(item) {
    var blockForm = item.closest('form');
    var stage = item.querySelector('[data-focal-admin-stage]');
    var image = item.querySelector('[data-focal-admin-image]');
    var hitArea = item.querySelector('[data-focal-admin-hitarea]');
    var selection = item.querySelector('[data-focal-admin-selection]');
    var captionPin = item.querySelector('[data-focal-admin-caption-pin]');
    var inputs = getInputs(item);
    var modeButtons = item.querySelectorAll('[data-focal-admin-mode]');
    var dragStart = null;
    var movedDuringPointer = false;
    var mode = 'focus';

    if (!blockForm || !stage || !image || !hitArea || !selection || !captionPin) {
      return;
    }

    function renderState() {
      renderHitArea(stage, image, hitArea);
      renderBox(stage, image, selection, readBox(inputs));
      renderCaptionPin(stage, captionPin, readCaption(inputs));
    }

    function setMode(nextMode) {
      mode = nextMode;
      Array.prototype.forEach.call(modeButtons, function (button) {
        button.classList.toggle('is-active', button.dataset.focalAdminMode === nextMode);
      });
      stage.dataset.focalAdminMode = nextMode;
    }

    ensureFormObserver(blockForm);
    syncImageFromWidget(blockForm, item);
    renderState();

    Array.prototype.forEach.call(modeButtons, function (button) {
      button.addEventListener('click', function () {
        setMode(button.dataset.focalAdminMode || 'focus');
      });

      button.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') {
          return;
        }
        event.preventDefault();
        setMode(button.dataset.focalAdminMode || 'focus');
      });
    });

    image.addEventListener('load', renderState);
    window.addEventListener('resize', renderState);

    hitArea.addEventListener('pointerdown', function (event) {
      if (item.classList.contains('is-empty') || mode !== 'focus') {
        return;
      }

      dragStart = pointerToPercent(hitArea.getBoundingClientRect(), event.clientX, event.clientY);
      movedDuringPointer = false;
      hitArea.setPointerCapture(event.pointerId);
      var box = boxFromDrag(dragStart, dragStart);
      writeBox(inputs, box);
      renderState();
      event.preventDefault();
    });

    hitArea.addEventListener('pointermove', function (event) {
      if (!dragStart) {
        return;
      }
      movedDuringPointer = true;
      var current = pointerToPercent(hitArea.getBoundingClientRect(), event.clientX, event.clientY);
      var box = boxFromDrag(dragStart, current);
      writeBox(inputs, box);
      renderState();
    });

    function finishDrag(event) {
      if (!dragStart) {
        return;
      }
      dragStart = null;
      if (event && hitArea.hasPointerCapture(event.pointerId)) {
        hitArea.releasePointerCapture(event.pointerId);
      }
    }

    hitArea.addEventListener('pointerup', finishDrag);
    hitArea.addEventListener('pointercancel', finishDrag);

    hitArea.addEventListener('click', function (event) {
      if (item.classList.contains('is-empty') || mode !== 'caption' || movedDuringPointer) {
        movedDuringPointer = false;
        return;
      }

      var caption = pointerToPercent(hitArea.getBoundingClientRect(), event.clientX, event.clientY);
      writeCaption(inputs, caption);
      renderState();
    });

    ['x', 'y', 'width', 'height', 'captionX', 'captionY'].forEach(function (key) {
      if (!inputs[key]) {
        return;
      }
      inputs[key].addEventListener('input', function () {
        renderState();
      });
    });

    setMode('focus');
  }

  Drupal.behaviors.moodyFocalPointAdmin = {
    attach: function (context) {
      once('moody-focal-point-admin', '[data-focal-admin-item]', context).forEach(initItem);
    }
  };

})(Drupal, once);