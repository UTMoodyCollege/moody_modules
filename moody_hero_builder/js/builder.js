/**
 * Native-DOM hero editor. The JSON field is submitted through Drupal's form.
 * Public image heroes use the same scoped CSS without JavaScript.
 */
(function (Drupal, once) {
  'use strict';

  const positions = ['top-left', 'top-center', 'top-right', 'center-left', 'center', 'center-right', 'bottom-left', 'bottom-center', 'bottom-right'];
  const devices = { desktop: 1280, tablet: 768, mobile: 375 };
  const labels = { heading: 'Heading', text: 'Text area', eyebrow: 'Intro label', button: 'UT button' };
  const make = (tag, attributes = {}, text = '') => {
    const node = document.createElement(tag);
    Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, value));
    node.textContent = text;
    return node;
  };
  const button = (label, action, attributes = {}) => {
    const node = make('button', { type: 'button', ...attributes }, Drupal.t(label));
    node.addEventListener('click', action);
    return node;
  };
  const clone = (value) => JSON.parse(JSON.stringify(value));
  const validUrl = (url) => {
    if (/[\s\x00-\x1f\x7f\\]/.test(url) || /%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i.test(url)) return false;
    if (/^\/(?!\/|%2f)/i.test(url) || /^#[A-Za-z][A-Za-z0-9_:.\-]*$/.test(url)) return true;
    try {
      const parsed = new URL(url);
      return url.startsWith('https://') && parsed.protocol === 'https:' && !parsed.username && !parsed.password;
    } catch (_) { return false; }
  };

  function init(root) {
    const source = root.querySelector('[data-hero-source]');
    let state;
    try {
      state = JSON.parse(source.value);
      state.background ??= 'image';
      state.video_url ??= '';
      if (!Array.isArray(state.elements) || !state.elements.length) throw new Error('Missing elements');
    } catch (_) {
      root.prepend(make('p', { role: 'alert' }, Drupal.t('The visual editor could not read this hero. Correct the configuration below before saving.')));
      return;
    }
    const sourceItem = source.closest('.form-item') || source;
    sourceItem.hidden = true;
    let selected = state.elements[0].id;
    let device = 'desktop';
    let tab = 'design';
    let imageUrl = '';
    let imageAlt = '';
    let dragging = false;
    const history = [];
    const future = [];
    const editor = make('div', { 'data-hero-editor': '' });
    const toolbar = make('div', { class: 'mhb-toolbar' });
    toolbar.append(make('strong', { class: 'mhb-title' }, Drupal.t('Moody Hero Builder')));
    const deviceGroup = make('div', { class: 'mhb-toolbar-group', role: 'group', 'aria-label': Drupal.t('Preview device') });
    Object.keys(devices).forEach((name) => deviceGroup.append(button(name.charAt(0).toUpperCase() + name.slice(1), () => {
      device = name;
      renderAll();
      announce(Drupal.t('@device preview. The published hero responds to its available width.', { '@device': name }));
    }, { 'data-device': name, 'aria-pressed': name === device ? 'true' : 'false' })));
    const undo = button('Undo', () => travel(history, future), { 'aria-label': Drupal.t('Undo hero change') });
    const redo = button('Redo', () => travel(future, history), { 'aria-label': Drupal.t('Redo hero change') });
    toolbar.append(deviceGroup, undo, redo);
    const workspace = make('div', { class: 'mhb-workspace' });
    const inspector = make('div', { class: 'mhb-inspector' });
    const tabs = make('div', { class: 'mhb-tabs', role: 'group', 'aria-label': Drupal.t('Hero settings') });
    ['design', 'content', 'image'].forEach((name) => tabs.append(button(name.charAt(0).toUpperCase() + name.slice(1), () => {
      tab = name;
      renderInspector();
    }, { 'data-panel': name, 'aria-pressed': 'false' })));
    const fields = make('div', { class: 'mhb-fields' });
    const mediaPanel = make('div', { class: 'mhb-media-panel' });
    const mediaWidget = root.querySelector('[data-hero-media]');
    if (mediaWidget) mediaPanel.append(mediaWidget);
    inspector.append(tabs, fields, mediaPanel);
    const previewArea = make('div', { class: 'mhb-preview-area' });
    const previewHead = make('div', { class: 'mhb-preview-head' });
    const sizeLabel = make('span');
    previewHead.append(make('strong', {}, Drupal.t('Canvas')), sizeLabel);
    const viewport = make('div', { class: 'mhb-viewport' });
    const stage = make('div', { class: 'mhb-stage mhb-hero', 'data-hero-canvas': '' });
    viewport.append(stage);
    const previewNote = make('p', { class: 'mhb-note' });
    const orderTitle = make('h3', { class: 'mhb-reading-title' }, Drupal.t('Content · reading order'));
    const order = make('ol', { class: 'mhb-order', 'aria-label': Drupal.t('Hero content reading order') });
    const add = make('div', { class: 'mhb-add' });
    const addLabel = make('label', { class: 'mhb-control' });
    addLabel.append(make('span', {}, Drupal.t('Add an element')));
    const addType = make('select', { 'aria-label': Drupal.t('New element type') });
    ['text', 'button', 'eyebrow'].forEach((type) => addType.append(make('option', { value: type }, Drupal.t(labels[type]))));
    addLabel.append(addType);
    const addButton = button('Add element', () => {
      if (state.elements.length >= 12) return;
      change(() => {
        const type = addType.value;
        const id = `item-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 7)}`;
        state.elements.push({ id, type, text: type === 'button' ? 'Explore Moody' : type === 'eyebrow' ? 'Moody College' : 'Add your supporting text.', size: 'medium', font: 'sans', url: type === 'button' ? '/' : '', style: 'primary' });
        selected = id;
        tab = 'content';
      });
      fields.querySelector('textarea, input')?.focus();
      announce(Drupal.t('Element added. Edit its text and destination before saving.'));
    });
    add.append(addLabel, addButton);
    previewArea.append(previewHead, viewport, previewNote, orderTitle, order, add);
    workspace.append(inspector, previewArea);
    const status = make('div', { class: 'mhb-status', role: 'status', 'aria-live': 'polite', 'data-state': 'success' });
    const guardrails = make('p', { class: 'mhb-note' }, Drupal.t('Brand fonts and readable text surfaces are built in. Mobile and tablet stack the image above the content. Check heading level, image description, and link wording before publishing.'));
    const saveNote = make('p', { class: 'mhb-note' }, Drupal.t('Changes stay in this form until you add or update the block. Save the layout afterward to publish them.'));
    editor.append(toolbar, workspace, status, guardrails, saveNote);
    root.append(editor);

    function announce(message) { status.textContent = message; }
    function remember(before) {
      if (JSON.stringify(before) === JSON.stringify(state)) return;
      history.push(before);
      if (history.length > 50) history.shift();
      future.length = 0;
    }
    function persist() {
      source.value = JSON.stringify(state);
      source.dispatchEvent(new Event('input', { bubbles: true }));
      undo.disabled = !history.length;
      redo.disabled = !future.length;
      addButton.disabled = state.elements.length >= 12;
      check();
    }
    function change(fn) {
      const before = clone(state);
      fn();
      remember(before);
      persist();
      renderAll();
    }
    function travel(from, to) {
      if (!from.length) return;
      to.push(clone(state));
      state = from.pop();
      if (!state.elements.some((item) => item.id === selected)) selected = state.elements[0].id;
      persist();
      renderAll();
      announce(Drupal.t('Hero change restored.'));
    }
    function check() {
      let issue = '';
      if (state.background === 'video' && (!state.video_url || !imageUrl || !state.decorative || state.layout === 'text')) issue = Drupal.t('Video needs a Vimeo/YouTube URL, a poster image, a visual layout, and decorative media.');
      if (!state.decorative && !state.image_alt.trim()) issue = Drupal.t('Add an image description, or mark the image as decorative.');
      if (state.elements.some((item) => !item.text.trim())) issue = Drupal.t('An element is empty. Add text or remove it before saving.');
      if (state.elements.some((item) => item.type === 'button' && !validUrl(item.url))) issue = Drupal.t('A button needs a /site-path, #anchor, or complete https:// URL.');
      status.dataset.state = issue ? 'error' : 'success';
      status.textContent = issue || Drupal.t('Ready to preview · brand palette and readable text surfaces applied.');
    }
    function control(label, key, options = null, target = state, type = 'text', limits = {}) {
      const wrapper = make('label', { class: 'mhb-control' });
      const caption = make('span', {}, Drupal.t(label));
      let input;
      if (options) {
        input = make('select');
        Object.entries(options).forEach(([value, title]) => input.append(make('option', { value }, Drupal.t(title))));
      } else {
        input = make(type === 'textarea' ? 'textarea' : 'input', type === 'textarea' ? { rows: 4 } : { type });
      }
      Object.entries(limits).forEach(([key, value]) => input.setAttribute(key, value));
      input.value = target[key];
      input.dataset.heroSetting = key;
      input.addEventListener('focus', () => { input._heroBefore = clone(state); });
      input.addEventListener('input', () => {
        target[key] = type === 'range' ? Number(input.value) : input.value;
        if (type === 'range') caption.textContent = `${Drupal.t(label)} · ${input.value}%`;
        persist();
        renderPreview();
        if (key === 'text') renderOrder();
      });
      input.addEventListener('change', () => {
        remember(input._heroBefore || clone(state));
        input._heroBefore = clone(state);
        persist();
        if (options && target === state) {
          renderAll();
          fields.querySelector(`[data-hero-setting="${key}"]`)?.focus({ preventScroll: true });
        }
      });
      if (type === 'range') caption.textContent = `${Drupal.t(label)} · ${input.value}%`;
      wrapper.append(caption, input);
      return wrapper;
    }
    function renderInspector() {
      fields.replaceChildren();
      tabs.querySelectorAll('button').forEach((node) => node.setAttribute('aria-pressed', node.dataset.panel === tab ? 'true' : 'false'));
      mediaPanel.hidden = tab !== 'image';
      if (tab === 'design') {
        fields.append(
          control('Layout', 'layout', { split: 'Split · image right', 'split-right': 'Split · image left', overlay: 'Image with overlay', text: 'Type-led · no image' }),
          control('Brand palette', 'scheme', { light: 'White + charcoal', dark: 'Charcoal + white', orange: 'Burnt orange + white' }),
          control('Hero height', 'height', { compact: 'Compact', standard: 'Standard', tall: 'Tall' }),
          control('Text alignment', 'alignment', { left: 'Left aligned', center: 'Centered' }),
          control('Heading level', 'heading_level', { h2: 'H2 · section heading', h1: 'H1 · page heading', h3: 'H3 · subsection' }),
        );
        if (['overlay', 'text'].includes(state.layout)) {
          fields.append(control('Content width', 'width', { narrow: 'Narrow', medium: 'Medium', wide: 'Wide' }));
          if (state.layout === 'overlay') fields.append(control('Text surface', 'surface', { solid: 'Solid · maximum clarity', soft: 'Soft · protected contrast' }));
          const title = make('h3', {}, Drupal.t('Desktop content position'));
          const grid = make('div', { class: 'mhb-position-grid', role: 'group', 'aria-label': Drupal.t('Content position') });
          const arrows = ['↖', '↑', '↗', '←', '•', '→', '↙', '↓', '↘'];
          positions.forEach((position, index) => grid.append(button(arrows[index], () => change(() => { state.position = position; }), { 'aria-label': position.replaceAll('-', ' '), 'aria-pressed': state.position === position ? 'true' : 'false' })));
          fields.append(title, grid);
        }
      } else if (tab === 'content') {
        const item = state.elements.find((element) => element.id === selected) || state.elements[0];
        fields.append(make('h3', {}, Drupal.t(labels[item.type])), control('Text', 'text', null, item, 'textarea', { maxlength: item.type === 'text' ? 1200 : item.type === 'button' ? 40 : 160 }));
        if (['heading', 'text'].includes(item.type)) {
          fields.append(control('Font size', 'size', { small: 'Small', medium: 'Medium', large: 'Large' }, item), control('Brand font', 'font', { sans: 'Libre Franklin', serif: 'Charis SIL' }, item));
        }
        if (item.type === 'button') fields.append(control('Destination', 'url', null, item, 'text', { maxlength: 2048, placeholder: '/about' }), control('Button style', 'style', { primary: 'Primary · filled', secondary: 'Secondary · outline' }, item));
        if (item.type !== 'heading') fields.append(button('Remove element', () => {
          change(() => { state.elements = state.elements.filter((element) => element.id !== item.id); selected = state.elements[0].id; });
          announce(Drupal.t('Element removed. Undo is available.'));
        }));
        else fields.append(make('p', { class: 'mhb-note' }, Drupal.t('Every hero keeps one heading. Set its semantic level in Design, independently of its font size.')));
      } else {
        fields.append(control('Background type', 'background', { image: 'Image', video: 'Vimeo / YouTube video' }));
        if (state.background === 'video') {
          fields.append(control('Video URL', 'video_url', null, state, 'url', { maxlength: 2048, placeholder: 'https://vimeo.com/123456789' }));
          fields.append(make('p', { class: 'mhb-note' }, Drupal.t('Choose a poster image below. Video is decorative, muted and looping. The canvas previews the poster; check playback on the page. Mobile, reduced-motion and data-saving visitors see the poster until they press Play. Pausing restores the poster; playing restarts the video.')));
        }
        const checkLabel = make('label', { class: 'mhb-check' });
        const checkBox = make('input', { type: 'checkbox' });
        checkBox.checked = state.decorative;
        checkBox.addEventListener('change', () => change(() => { state.decorative = checkBox.checked; }));
        checkLabel.append(checkBox, make('span', {}, Drupal.t('Image is decorative')));
        fields.append(checkLabel);
        if (!state.decorative) {
          fields.append(control('Image description', 'image_alt', null, state, 'textarea', { maxlength: 300 }));
          if (imageAlt) fields.append(button('Use Media Library description', () => change(() => { state.image_alt = imageAlt.slice(0, 300); })));
        }
        const mobile = device !== 'desktop';
        fields.append(make('h3', {}, Drupal.t(mobile ? 'Mobile / tablet image crop' : 'Desktop image crop')),
          control('Horizontal focal point', mobile ? 'mobile_focal_x' : 'focal_x', null, state, 'range', { min: 0, max: 100, step: 1 }),
          control('Vertical focal point', mobile ? 'mobile_focal_y' : 'focal_y', null, state, 'range', { min: 0, max: 100, step: 1 }),
          control('Image overlay', 'overlay', { none: 'None', charcoal: 'Charcoal', orange: 'Burnt orange' }),
          control('Overlay strength', 'overlay_strength', null, state, 'range', { min: 0, max: 100, step: 1 }));
        fields.append(make('p', { class: 'mhb-note' }, Drupal.t('Image overlays are decorative. The text surface separately preserves contrast, even when the overlay is set to zero.')));
      }
    }
    function sceneClass() {
      return `mhb-scene mhb-layout--${state.layout} mhb-scheme--${state.scheme} mhb-surface--${state.surface} mhb-position--${state.position} mhb-align--${state.alignment} mhb-height--${state.height} mhb-width--${state.width} mhb-overlay--${state.overlay}${imageUrl ? ' mhb-has-image' : ''}`;
    }
    function renderPreview() {
      if (dragging) return;
      const scene = make('section', { class: sceneClass() });
      ['focal_x', 'focal_y', 'mobile_focal_x', 'mobile_focal_y'].forEach((key) => scene.style.setProperty(`--mhb-${key.replaceAll('_', '-')}`, `${state[key]}%`));
      scene.style.setProperty('--mhb-overlay-opacity', state.overlay_strength / 100);
      if (imageUrl && state.layout !== 'text') {
        const media = make('div', { class: 'mhb-media' });
        const img = make('img', { src: imageUrl, alt: state.decorative ? '' : state.image_alt, width: '2280', height: '1232' });
        img.addEventListener('error', () => { status.dataset.state = 'error'; announce(Drupal.t('The selected image could not load. Choose another image or check file availability.')); }, { once: true });
        media.append(img);
        scene.append(media);
      }
      const content = make('div', { class: 'mhb-content' });
      state.elements.forEach((item) => {
        const wrapper = make('div', { class: `mhb-element mhb-element--${item.type} mhb-size--${item.size} mhb-font--${item.font}${selected === item.id ? ' is-selected' : ''}`, tabindex: '0', role: 'button', 'aria-label': Drupal.t('Edit @type: @text', { '@type': labels[item.type], '@text': item.text.slice(0, 60) }) });
        // The canvas wrapper edits the element; do not nest a live link inside
        // that button. The public Twig template renders the actual destination.
        const child = make(item.type === 'heading' ? (['h1','h2','h3'].includes(state.heading_level) ? state.heading_level : 'h2') : item.type === 'button' ? 'span' : 'p', item.type === 'button' ? { class: `mhb-button mhb-button--${item.style}` } : {}, item.text || Drupal.t('Empty element'));
        wrapper.append(child);
        const select = () => { selected = item.id; tab = 'content'; renderInspector(); renderOrder(); stage.querySelectorAll('.mhb-element').forEach((node) => node.classList.toggle('is-selected', node === wrapper)); fields.querySelector('textarea')?.focus({ preventScroll: true }); };
        wrapper.addEventListener('click', (event) => { event.preventDefault(); select(); });
        wrapper.addEventListener('keydown', (event) => { if (['Enter', ' '].includes(event.key)) { event.preventDefault(); select(); } });
        content.append(wrapper);
      });
      if (device === 'desktop' && ['overlay', 'text'].includes(state.layout)) {
        const move = button('Move content', () => {}, { class: 'mhb-move', 'aria-label': Drupal.t('Move content. Drag to a position, or use the arrow keys. Escape cancels a drag.') });
        move.addEventListener('keydown', (event) => {
          const delta = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -3, ArrowDown: 3 }[event.key];
          if (delta === undefined) return;
          event.preventDefault();
          const current = positions.indexOf(state.position);
          const next = current + delta;
          if (next < 0 || next > 8 || (Math.abs(delta) === 1 && Math.floor(current / 3) !== Math.floor(next / 3))) return;
          change(() => { state.position = positions[next]; });
          stage.querySelector('.mhb-move')?.focus({ preventScroll: true });
          announce(Drupal.t('Content position: @position', { '@position': state.position.replaceAll('-', ' ') }));
        });
        move.addEventListener('pointerdown', (event) => startPositionDrag(event, scene, move));
        content.append(move);
      }
      scene.append(content);
      stage.replaceChildren(scene);
      fit();
      previewNote.textContent = device === 'desktop' ? Drupal.t('Select text on the canvas to edit. In overlay or type-led layouts, drag “Move content” to reposition the content stack.') : Drupal.t('Small-screen layout: image above content, no overlaps. Reading order stays the same at every width.');
    }
    function startPositionDrag(event, scene, handle) {
      if (event.button !== 0) return;
      event.preventDefault();
      const before = clone(state);
      dragging = true;
      handle.setPointerCapture(event.pointerId);
      const move = (pointer) => {
        const rect = scene.getBoundingClientRect();
        const col = Math.max(0, Math.min(2, Math.floor((pointer.clientX - rect.left) / rect.width * 3)));
        const row = Math.max(0, Math.min(2, Math.floor((pointer.clientY - rect.top) / rect.height * 3)));
        state.position = positions[row * 3 + col];
        scene.className = sceneClass();
      };
      const finish = (cancelled = false) => {
        handle.removeEventListener('pointermove', move);
        handle.removeEventListener('pointerup', up);
        handle.removeEventListener('pointercancel', cancel);
        document.removeEventListener('keydown', escape);
        dragging = false;
        if (cancelled) state = before;
        else remember(before);
        persist(); renderAll();
        stage.querySelector('.mhb-move')?.focus({ preventScroll: true });
        announce(cancelled ? Drupal.t('Move cancelled.') : Drupal.t('Content moved to @position.', { '@position': state.position.replaceAll('-', ' ') }));
      };
      const up = () => finish();
      const cancel = () => finish(true);
      const escape = (key) => { if (key.key === 'Escape') { key.preventDefault(); finish(true); } };
      handle.addEventListener('pointermove', move);
      handle.addEventListener('pointerup', up);
      handle.addEventListener('pointercancel', cancel);
      document.addEventListener('keydown', escape);
    }
    function reorder(id, next) {
      const index = state.elements.findIndex((item) => item.id === id);
      if (next < 0 || next >= state.elements.length || index === next) return;
      change(() => { const [item] = state.elements.splice(index, 1); state.elements.splice(next, 0, item); });
      order.querySelector(`[data-element-row="${id}"] .mhb-drag`)?.focus({ preventScroll: true });
      announce(Drupal.t('Element moved to position @position. Reading order updated.', { '@position': next + 1 }));
    }
    function renderOrder() {
      order.replaceChildren();
      state.elements.forEach((item, index) => {
        const row = make('li', { class: 'mhb-row', 'data-element-row': item.id });
        const drag = button('↕', () => {}, { class: 'mhb-drag', 'aria-label': Drupal.t('Reorder @type. Drag or use up and down arrow keys.', { '@type': labels[item.type] }) });
        drag.addEventListener('keydown', (event) => {
          if (['ArrowUp', 'ArrowDown'].includes(event.key)) { event.preventDefault(); reorder(item.id, index + (event.key === 'ArrowUp' ? -1 : 1)); }
        });
        drag.addEventListener('pointerdown', (event) => {
          if (event.button !== 0) return;
          event.preventDefault();
          drag.setPointerCapture(event.pointerId);
          let destination = index;
          const move = (pointer) => {
            const target = document.elementFromPoint(pointer.clientX, pointer.clientY)?.closest('[data-element-row]');
            order.querySelectorAll('.mhb-row').forEach((node) => node.classList.toggle('is-drop-target', node === target));
            if (target && order.contains(target)) destination = state.elements.findIndex((element) => element.id === target.dataset.elementRow);
          };
          const cleanup = () => { drag.removeEventListener('pointermove', move); drag.removeEventListener('pointerup', finish); drag.removeEventListener('pointercancel', cancel); document.removeEventListener('keydown', escape); order.querySelectorAll('.mhb-row').forEach((node) => node.classList.remove('is-drop-target')); };
          const finish = () => { cleanup(); reorder(item.id, destination); };
          const cancel = () => { cleanup(); announce(Drupal.t('Reorder cancelled.')); };
          const escape = (key) => { if (key.key === 'Escape') { key.preventDefault(); cancel(); } };
          drag.addEventListener('pointermove', move);
          drag.addEventListener('pointerup', finish);
          drag.addEventListener('pointercancel', cancel);
          document.addEventListener('keydown', escape);
        });
        const select = button(`${labels[item.type]} · ${item.text.slice(0, 38)}`, () => { selected = item.id; tab = 'content'; renderAll(); fields.querySelector('textarea')?.focus({ preventScroll: true }); }, { 'data-select': '', 'aria-pressed': selected === item.id ? 'true' : 'false' });
        const up = button('↑', () => reorder(item.id, index - 1), { 'aria-label': Drupal.t('Move @type up', { '@type': labels[item.type] }) });
        const down = button('↓', () => reorder(item.id, index + 1), { 'aria-label': Drupal.t('Move @type down', { '@type': labels[item.type] }) });
        up.disabled = index === 0;
        down.disabled = index === state.elements.length - 1;
        row.append(drag, select, up, down);
        order.append(row);
      });
    }
    function fit() {
      const width = devices[device];
      stage.style.width = `${width}px`;
      const scale = Math.min(1, viewport.clientWidth / width);
      stage.style.transform = `scale(${scale})`;
      viewport.style.height = `${stage.scrollHeight * scale}px`;
      sizeLabel.textContent = `${width}px · ${Math.round(scale * 100)}%`;
    }
    function renderAll() {
      deviceGroup.querySelectorAll('button').forEach((node) => node.setAttribute('aria-pressed', node.dataset.device === device ? 'true' : 'false'));
      renderInspector(); renderPreview(); renderOrder();
    }
    function readMedia() {
      const widget = mediaPanel.querySelector('[data-hero-media]');
      const url = widget?.dataset.heroImageUrl || '';
      imageAlt = widget?.dataset.heroImageAlt || '';
      if (url !== imageUrl) { imageUrl = url; renderPreview(); }
    }
    const resize = new ResizeObserver(fit);
    resize.observe(viewport);
    resize.observe(stage);
    const mediaObserver = new MutationObserver(readMedia);
    mediaObserver.observe(mediaPanel, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-hero-image-url'] });
    root._heroBuilderCleanup = () => { resize.disconnect(); mediaObserver.disconnect(); };
    readMedia(); renderAll(); persist();
  }

  Drupal.behaviors.moodyHeroBuilder = {
    attach(context) { once('moody-hero-builder', '[data-moody-hero-builder]', context).forEach(init); },
    detach(context, settings, trigger) {
      if (trigger === 'unload') once.remove('moody-hero-builder', '[data-moody-hero-builder]', context).forEach((root) => root._heroBuilderCleanup?.());
    },
  };
})(Drupal, once);
