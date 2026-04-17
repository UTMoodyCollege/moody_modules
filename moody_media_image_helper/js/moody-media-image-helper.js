(function (Drupal, once) {
  "use strict";

  function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
  }

  function initCropWorkspace(workspace) {
    const stage = workspace.querySelector("[data-moody-media-helper-stage]");
    const image = workspace.querySelector("[data-moody-media-helper-image]");
    const selection = workspace.querySelector("[data-moody-media-helper-selection]");
    const label = workspace.querySelector("[data-moody-media-helper-selection-label]");
    const sizeReadout = workspace.querySelector("[data-moody-media-helper-size]");
    const offsetReadout = workspace.querySelector("[data-moody-media-helper-offset]");
    const inputs = {
      x: workspace.closest("form").querySelector("[data-moody-media-helper-input='x']"),
      y: workspace.closest("form").querySelector("[data-moody-media-helper-input='y']"),
      width: workspace.closest("form").querySelector("[data-moody-media-helper-input='width']"),
      height: workspace.closest("form").querySelector("[data-moody-media-helper-input='height']")
    };

    const state = {
      naturalWidth: Number(workspace.dataset.originalWidth || 0),
      naturalHeight: Number(workspace.dataset.originalHeight || 0),
      rect: { x: 0, y: 0, width: 0, height: 0 },
      dragMode: "move",
      handle: null,
      pointerId: null,
      startPointer: null,
      startRect: null
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

    function updateReadout() {
      const scale = displayScale();
      const width = Math.round(state.rect.width * scale.x);
      const height = Math.round(state.rect.height * scale.y);
      const x = Math.round(state.rect.x * scale.x);
      const y = Math.round(state.rect.y * scale.y);

      selection.style.left = state.rect.x + "px";
      selection.style.top = state.rect.y + "px";
      selection.style.width = state.rect.width + "px";
      selection.style.height = state.rect.height + "px";

      label.textContent = width + " × " + height + " px";
      sizeReadout.textContent = width + " × " + height + " px";
      offsetReadout.textContent = x + ", " + y;
      inputs.x.value = x;
      inputs.y.value = y;
      inputs.width.value = width;
      inputs.height.value = height;
    }

    function setDefaultRect() {
      const rect = stageRect();
      const width = rect.width * 0.72;
      const height = rect.height * 0.72;
      state.rect = {
        x: (rect.width - width) / 2,
        y: (rect.height - height) / 2,
        width,
        height
      };
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
      state.rect = { x: startX, y: startY, width: 24, height: 24 };
      updateReadout();
      startInteraction(event, "draw");
    });

    stage.addEventListener("pointermove", onPointerMove);
    stage.addEventListener("pointerup", onPointerUp);
    stage.addEventListener("pointercancel", onPointerUp);

    if (image.complete) {
      setDefaultRect();
    }
    else {
      image.addEventListener("load", setDefaultRect, { once: true });
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
    }

    const previewWrapper = document.getElementById(response.previewWrapperId);
    if (previewWrapper) {
      previewWrapper.outerHTML = response.previewHtml;
    }

    const actionWrapper = document.getElementById(response.actionWrapperId);
    if (actionWrapper) {
      actionWrapper.outerHTML = response.actionHtml;
    }

    const widgetRoot = document.getElementById(response.widgetRootId);
    if (widgetRoot && response.selectionInputId) {
      const selectionInput = document.getElementById(response.selectionInputId);
      if (selectionInput) {
        const ids = Array.from(widgetRoot.querySelectorAll(".moody-media-image-helper__target-input"))
          .map(function (input) { return input.value; })
          .filter(Boolean);
        selectionInput.value = ids.join(",");
      }
    }

    Drupal.attachBehaviors(widgetRoot || document);
  };
})(Drupal, once);
