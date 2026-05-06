(function (Drupal, once) {
  "use strict";

  function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
  }

  function initCropWorkspace(workspace) {
    const form = workspace.closest("form");
    const stage = workspace.querySelector("[data-moody-media-helper-stage]");
    const image = workspace.querySelector("[data-moody-media-helper-image]");
    const selection = workspace.querySelector("[data-moody-media-helper-selection]");
    const label = workspace.querySelector("[data-moody-media-helper-selection-label]");
    const sizeReadout = workspace.querySelector("[data-moody-media-helper-size]");
    const offsetReadout = workspace.querySelector("[data-moody-media-helper-offset]");
    const outputReadout = workspace.querySelector("[data-moody-media-helper-output-size]");
    const inputs = {
      x: form.querySelector("[data-moody-media-helper-input='x']"),
      y: form.querySelector("[data-moody-media-helper-input='y']"),
      width: form.querySelector("[data-moody-media-helper-input='width']"),
      height: form.querySelector("[data-moody-media-helper-input='height']")
    };
    const resizeInputs = {
      width: form.querySelector("[data-moody-media-helper-resize-input='width']"),
      height: form.querySelector("[data-moody-media-helper-resize-input='height']")
    };

    const state = {
      naturalWidth: Number(workspace.dataset.originalWidth || 0),
      naturalHeight: Number(workspace.dataset.originalHeight || 0),
      rect: { x: 0, y: 0, width: 0, height: 0 },
      hasSelection: false,
      dragMode: "move",
      handle: null,
      pointerId: null,
      startPointer: null,
      startRect: null,
      resizeCustomized: false
    };

    function stageRect() {
      return stage.getBoundingClientRect();
    }

    function displayScale() {
      const rect = image.getBoundingClientRect();
      return {
        x: state.naturalWidth / rect.width,
        y: state.naturalHeight / rect.height
      };
    }

    function getRequestedResize(cropWidth, cropHeight) {
      const width = Number.parseInt(resizeInputs.width && resizeInputs.width.value, 10);
      const height = Number.parseInt(resizeInputs.height && resizeInputs.height.value, 10);

      return {
        width: Number.isFinite(width) && width > 0 ? width : cropWidth,
        height: Number.isFinite(height) && height > 0 ? height : cropHeight
      };
    }

    function syncResizeInputs(width, height, force) {
      if (!resizeInputs.width || !resizeInputs.height) {
        return;
      }

      if (force || !state.resizeCustomized) {
        resizeInputs.width.value = String(width);
        resizeInputs.height.value = String(height);
      }
    }

    function updateReadout() {
      if (!state.hasSelection) {
        selection.classList.add("is-hidden");
        label.textContent = "";
        sizeReadout.textContent = "Drag to select";
        if (outputReadout) {
          outputReadout.textContent = getRequestedResize(state.naturalWidth, state.naturalHeight).width + " × " + getRequestedResize(state.naturalWidth, state.naturalHeight).height + " px";
        }
        offsetReadout.textContent = "0, 0";
        inputs.x.value = 0;
        inputs.y.value = 0;
        inputs.width.value = 0;
        inputs.height.value = 0;
        return;
      }

      const scale = displayScale();
      const width = Math.round(state.rect.width * scale.x);
      const height = Math.round(state.rect.height * scale.y);
      const x = Math.round(state.rect.x * scale.x);
      const y = Math.round(state.rect.y * scale.y);
      syncResizeInputs(width, height, false);
      const requestedResize = getRequestedResize(width, height);
      selection.classList.remove("is-hidden");

      selection.style.left = state.rect.x + "px";
      selection.style.top = state.rect.y + "px";
      selection.style.width = state.rect.width + "px";
      selection.style.height = state.rect.height + "px";

      label.textContent = width + " × " + height + " px";
      sizeReadout.textContent = width + " × " + height + " px";
      if (outputReadout) {
        outputReadout.textContent = requestedResize.width + " × " + requestedResize.height + " px";
      }
      offsetReadout.textContent = x + ", " + y;
      inputs.x.value = x;
      inputs.y.value = y;
      inputs.width.value = width;
      inputs.height.value = height;
    }

    function setDefaultState() {
      syncResizeInputs(Math.round(state.naturalWidth), Math.round(state.naturalHeight), true);
      updateReadout();
    }

    function normalizeRect(rect) {
      const bounds = stageRect();
      let next = { ...rect };
      if (next.width < 24) {
        next.width = 24;
      }
      if (next.height < 24) {
        next.height = 24;
      }
      next.x = clamp(next.x, 0, bounds.width - next.width);
      next.y = clamp(next.y, 0, bounds.height - next.height);
      next.width = clamp(next.width, 24, bounds.width - next.x);
      next.height = clamp(next.height, 24, bounds.height - next.y);
      return next;
    }

    function startInteraction(event, mode, handle) {
      event.preventDefault();
      state.pointerId = event.pointerId;
      state.dragMode = mode;
      state.handle = handle || null;
      state.startPointer = { x: event.clientX, y: event.clientY };
      state.startRect = { ...state.rect };
      stage.setPointerCapture(event.pointerId);
    }

    function onPointerMove(event) {
      if (state.pointerId !== event.pointerId || !state.startPointer) {
        return;
      }

      const dx = event.clientX - state.startPointer.x;
      const dy = event.clientY - state.startPointer.y;
      let next = { ...state.startRect };

      if (state.dragMode === "move") {
        next.x += dx;
        next.y += dy;
      }
      else if (state.dragMode === "draw") {
        next = {
          x: Math.min(state.startRect.x, state.startRect.x + dx),
          y: Math.min(state.startRect.y, state.startRect.y + dy),
          width: Math.abs(dx),
          height: Math.abs(dy)
        };
      }
      else if (state.dragMode === "resize") {
        if (state.handle.indexOf("n") !== -1) {
          next.y += dy;
          next.height -= dy;
        }
        if (state.handle.indexOf("s") !== -1) {
          next.height += dy;
        }
        if (state.handle.indexOf("w") !== -1) {
          next.x += dx;
          next.width -= dx;
        }
        if (state.handle.indexOf("e") !== -1) {
          next.width += dx;
        }
      }

      state.rect = normalizeRect(next);
      updateReadout();
    }

    function onPointerUp(event) {
      if (state.pointerId !== event.pointerId) {
        return;
      }
      state.pointerId = null;
      state.startPointer = null;
      state.startRect = null;
      state.handle = null;
      stage.releasePointerCapture(event.pointerId);
    }

    stage.addEventListener("pointerdown", function (event) {
      if (event.target.closest("[data-moody-media-helper-handle]")) {
        startInteraction(event, "resize", event.target.closest("[data-moody-media-helper-handle]").dataset.moodyMediaHelperHandle);
        return;
      }
      if (event.target.closest("[data-moody-media-helper-selection]")) {
        startInteraction(event, "move");
        return;
      }

      const bounds = stageRect();
      const startX = clamp(event.clientX - bounds.left, 0, bounds.width);
      const startY = clamp(event.clientY - bounds.top, 0, bounds.height);
      state.hasSelection = true;
      state.rect = { x: startX, y: startY, width: 24, height: 24 };
      updateReadout();
      startInteraction(event, "draw");
    });

    stage.addEventListener("pointermove", onPointerMove);
    stage.addEventListener("pointerup", onPointerUp);
    stage.addEventListener("pointercancel", onPointerUp);

    ["width", "height"].forEach(function (key) {
      const input = resizeInputs[key];
      if (!input) {
        return;
      }

      input.addEventListener("input", function () {
        state.resizeCustomized = true;
        updateReadout();
      });
    });

    if (image.complete) {
      setDefaultState();
    }
    else {
      image.addEventListener("load", setDefaultState, { once: true });
    }
  }

  Drupal.behaviors.moodyMediaImageHelper = {
    attach: function (context) {
      once("moody-media-image-helper-workspace", "[data-moody-media-helper-workspace]", context).forEach(initCropWorkspace);
    }
  };

  Drupal.AjaxCommands.prototype.moodyMediaImageHelperUpdateSelection = function (ajax, response) {
    const targetInput = document.getElementById(response.targetInputId);
    if (targetInput) {
      targetInput.value = String(response.mediaId);
      targetInput.dispatchEvent(new Event("change", { bubbles: true }));
    }

    const fileInput = response.fileInputId ? document.getElementById(response.fileInputId) : null;
    if (fileInput && response.fileId) {
      fileInput.value = String(response.fileId);
      fileInput.dispatchEvent(new Event("change", { bubbles: true }));
    }

    const previewWrapper = document.getElementById(response.previewWrapperId);
    if (previewWrapper) {
      previewWrapper.outerHTML = response.previewHtml;
    }

    const actionWrapper = document.getElementById(response.actionWrapperId);
    if (actionWrapper) {
      actionWrapper.outerHTML = response.actionHtml;
    }

    const widgetRoot = response.widgetRootId ? document.getElementById(response.widgetRootId) : null;
    if (widgetRoot && response.selectionInputId) {
      const selectionInput = document.getElementById(response.selectionInputId);
      if (selectionInput) {
        const ids = Array.from(widgetRoot.querySelectorAll(".moody-media-image-helper__target-input"))
          .map(function (input) { return input.value; })
          .filter(Boolean);
        selectionInput.value = ids.join(",");
        selectionInput.dispatchEvent(new Event("change", { bubbles: true }));
      }
    }

    Drupal.attachBehaviors(widgetRoot || document);
  };
})(Drupal, once);
