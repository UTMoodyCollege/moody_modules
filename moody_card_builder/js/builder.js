(function (Drupal, once) {
  'use strict';
  const devices = { mobile: 375, tablet: 768, desktop: 1280 };
  const splits = { full: 1, halves: 2, thirds: 3, 'one-two': 2, 'two-one': 2 };
  const clone = (value) => JSON.parse(JSON.stringify(value));
  const el = (tag, attrs = {}, text = '') => {
    const node = document.createElement(tag);
    Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, value));
    node.textContent = text;
    return node;
  };
  const button = (text, action, attrs = {}) => {
    const node = el('button', { type: 'button', ...attrs }, Drupal.t(text));
    node.addEventListener('click', action); return node;
  };
  const cell = () => ({ type: 'text', text: 'Add supporting text.', url: '', align: 'left', size: 'medium', font: 'sans', style: 'link' });
  const id = () => `item-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;

  function init(root) {
    const source = root.querySelector('[data-card-source]');
    let state;
    try { state = JSON.parse(source.value); if (!state.cards?.length) throw new Error('Missing cards'); }
    catch (_) { root.prepend(el('p', { role: 'alert' }, Drupal.t('Unable to load the visual editor. Correct the JSON below.'))); return; }
    state.radius = 'square';
    (source.closest('.form-item') || source).hidden = true;
    let selected = state.cards[0].id, device = 'desktop', tab = 'collection', images = {};
    const history = [], future = [];
    const editor = el('div', { 'data-card-editor': '' });
    const toolbar = el('div', { class: 'mcb-toolbar' });
    const undo = button('Undo', () => travel(history, future));
    const redo = button('Redo', () => travel(future, history));
    toolbar.append(el('strong', {}, Drupal.t('Moody Card Builder')));
    Object.keys(devices).reverse().forEach((name) => toolbar.append(button(name[0].toUpperCase() + name.slice(1), () => { device = name; render(); }, { 'data-device': name })));
    toolbar.append(undo, redo);
    const workspace = el('div', { class: 'mcb-workspace' });
    const inspector = el('div', { class: 'mcb-inspector' });
    const tabs = el('div', { class: 'mcb-tabs', role: 'group', 'aria-label': Drupal.t('Card settings') });
    ['collection', 'content', 'image'].forEach((name) => tabs.append(button(name[0].toUpperCase() + name.slice(1), () => { tab = name; inspect(); }, { 'data-tab': name })));
    const fields = el('div', { class: 'mcb-fields' });
    const media = el('div', { class: 'mcb-media-panel' });
    const widget = root.querySelector('[data-card-media]');
    if (widget) media.append(widget);
    inspector.append(tabs, fields, media);
    const canvasArea = el('div', { class: 'mcb-preview' });
    const caption = el('p');
    const viewport = el('div', { class: 'mcb-viewport' });
    const stage = el('div', { class: 'mcb-stage' });
    viewport.append(stage);
    const order = el('ol', { class: 'mcb-order', 'aria-label': Drupal.t('Card reading order') });
    const add = button('Add card', () => change(() => {
      if (state.cards.length >= 12) return;
      const card = clone(state.cards.find((item) => item.id === selected));
      card.id = id(); card.image = 0; card.alt = '';
      card.rows = [{ id: id(), split: 'full', stack_mobile: true, divider: false, bottom: false, cells: [{ ...cell(), type: 'heading', text: 'New card' }] }, { id: id(), split: 'full', stack_mobile: true, divider: false, bottom: false, cells: [cell()] }];
      state.cards.push(card); selected = card.id; tab = 'content';
    }));
    canvasArea.append(caption, viewport, el('h3', {}, Drupal.t('Cards · reading order')), order, add);
    workspace.append(inspector, canvasArea);
    const status = el('p', { role: 'status', 'aria-live': 'polite', class: 'mcb-status' });
    editor.append(toolbar, workspace, status, el('p', { class: 'mcb-note' }, Drupal.t('Use drag handles or Move up/down to reorder. Preview widths match the public card container, not the full browser. Text grows to fit; nothing is clipped. Changes stay in this form until you update the block and save the layout.')));
    root.append(editor);

    function save() {
      source.value = JSON.stringify(state); source.dispatchEvent(new Event('input', { bubbles: true }));
      undo.disabled = !history.length; redo.disabled = !future.length; add.disabled = state.cards.length >= 12;
      const invalid = state.cards.some((card) => (card.image && !images[card.image]) || (card.image && !card.decorative && !card.alt.trim()) || card.rows.flatMap((row) => row.cells).filter((item) => item.type === 'heading').length !== 1 || card.rows.some((row) => row.cells.some((item) => !item.text.trim() || (item.type === 'button' && !item.url.trim()))));
      status.dataset.error = invalid ? 'true' : 'false';
      status.textContent = Drupal.t(invalid ? 'Check each card: one heading, filled elements, link destinations, and accessible images with descriptions or decorative status.' : 'Brand palette applied. Check image descriptions, heading level, link wording and all three previews before saving.');
    }
    function remember(before) { if (JSON.stringify(before) !== JSON.stringify(state)) { history.push(before); if (history.length > 50) history.shift(); future.length = 0; } }
    function change(action) { const before = clone(state); action(); remember(before); normalizeSelection(); save(); render(); }
    function normalizeSelection() { if (!state.cards.some((card) => card.id === selected)) selected = state.cards[0].id; }
    function travel(from, to) { if (!from.length) return; to.push(clone(state)); state = from.pop(); normalizeSelection(); save(); render(); }
    function control(label, target, key, options = null, type = 'text', attrs = {}) {
      const wrapper = el('label', { class: 'mcb-control' });
      const input = el(options ? 'select' : type === 'textarea' ? 'textarea' : 'input', options ? attrs : { type, ...attrs });
      if (options) Object.entries(options).forEach(([value, title]) => input.append(el('option', { value }, Drupal.t(title))));
      input.value = target[key]; input.dataset.setting = key;
      let before = clone(state);
      input.addEventListener('focus', () => { before = clone(state); });
      input.addEventListener('input', () => { target[key] = ['number', 'range'].includes(type) || key === 'image' ? Number(input.value) : input.value; save(); preview(); });
      input.addEventListener('change', () => { remember(before); before = clone(state); save(); if (options) { const selector = `[data-setting="${key}"]`; const index = [...fields.querySelectorAll(selector)].indexOf(input); inspect(); fields.querySelectorAll(selector)[index]?.focus({ preventScroll: true }); } drawOrder(); });
      wrapper.append(el('span', {}, Drupal.t(label)), input); return wrapper;
    }
    function check(label, target, key) {
      const wrapper = el('label', { class: 'mcb-check' }); const input = el('input', { type: 'checkbox' }); input.checked = target[key];
      input.addEventListener('change', () => change(() => { target[key] = input.checked; })); wrapper.append(input, el('span', {}, Drupal.t(label))); return wrapper;
    }
    function reorder(list, index, destination) {
      if (destination < 0 || destination >= list.length || destination === index) return;
      const moved = list[index].id;
      change(() => { list.splice(destination, 0, list.splice(index, 1)[0]); });
      editor.querySelector(`[data-sort-id="${moved}"] button`)?.focus({ preventScroll: true });
      status.textContent = Drupal.t('Item moved to position @number.', { '@number': destination + 1 });
    }
    function moves(list, index, row, scope) {
      row.dataset.sortId = list[index].id;
      const handle = button('↕', () => {}, { class: 'mcb-drag', 'aria-label': Drupal.t('Reorder item: drag, or use Up and Down arrow keys') });
      handle.addEventListener('keydown', (event) => { if (['ArrowUp', 'ArrowDown'].includes(event.key)) { event.preventDefault(); reorder(list, index, index + (event.key === 'ArrowUp' ? -1 : 1)); } });
      handle.addEventListener('pointerdown', (event) => {
        if (event.button !== 0) return; event.preventDefault(); handle.setPointerCapture(event.pointerId); let destination = index;
        const move = (pointer) => { const target = document.elementFromPoint(pointer.clientX, pointer.clientY)?.closest('[data-sort-id]'); if (target && scope.contains(target)) { const next = list.findIndex((item) => item.id === target.dataset.sortId); if (next >= 0) destination = next; } };
        const cleanup = () => { handle.removeEventListener('pointermove', move); handle.removeEventListener('pointerup', end); handle.removeEventListener('pointercancel', cancel); document.removeEventListener('keydown', escape); };
        const end = () => { cleanup(); reorder(list, index, destination); };
        const cancel = () => { cleanup(); status.textContent = Drupal.t('Move cancelled.'); };
        const escape = (key) => { if (key.key === 'Escape') { key.preventDefault(); cancel(); } };
        handle.addEventListener('pointermove', move); handle.addEventListener('pointerup', end); handle.addEventListener('pointercancel', cancel); document.addEventListener('keydown', escape);
      });
      const up = button('↑', () => reorder(list, index, index - 1), { 'aria-label': Drupal.t('Move up') }); up.disabled = index === 0;
      const down = button('↓', () => reorder(list, index, index + 1), { 'aria-label': Drupal.t('Move down') }); down.disabled = index === list.length - 1;
      row.append(handle, up, down);
    }
    function inspect() {
      fields.replaceChildren(); media.hidden = tab !== 'image';
      tabs.querySelectorAll('button').forEach((node) => node.setAttribute('aria-pressed', String(node.dataset.tab === tab)));
      const card = state.cards.find((item) => item.id === selected);
      if (tab === 'collection') {
        fields.append(control(`Cards per row · ${device}`, state.columns, device, null, 'number', { min: 1, max: device === 'mobile' ? 2 : 4, step: 1 }), control('Gap', state, 'gap', { small: 'Small', medium: 'Medium', large: 'Large' }), control('Card heading level', state, 'heading_level', { h2: 'H2', h3: 'H3', h4: 'H4' }));
        fields.append(el('p', { class: 'mcb-note' }, Drupal.t('Select a card below or on the canvas to edit its content and image. New cards inherit the selected card’s design.')));
        return;
      }
      fields.append(el('h3', {}, Drupal.t('Selected card')), control('Card palette', card, 'scheme', { light: 'White', paper: 'Warm neutral', dark: 'Charcoal', orange: 'Burnt orange' }));
      if (tab === 'image') {
        const options = { 0: 'No image' }; Object.entries(images).forEach(([key, image]) => { options[key] = image.alt || `Image ${key}`; });
        if (card.image && !images[card.image]) options[card.image] = `Unavailable image ${card.image}`;
        fields.append(control('Image from card library', card, 'image', options), check('Image is decorative', card, 'decorative'));
        if (!card.decorative) fields.append(control('Image description', card, 'alt', null, 'textarea', { maxlength: 300 }), button('Use media description', () => change(() => { card.alt = images[card.image]?.alt || ''; })));
        const settings = card.responsive[device];
        fields.append(el('h3', {}, Drupal.t(`Image layout · ${device}`)), control('Image position', settings, 'layout', { top: 'Above text', left: 'Left of text', right: 'Right of text', none: 'Hide image' }), control('Image share (%)', settings, 'share', null, 'number', { min: 20, max: 80, step: 1 }), control('Minimum card height (px)', settings, 'height', null, 'number', { min: 240, max: 800, step: 10 }), control('Horizontal focal point (%)', settings, 'focal_x', null, 'range', { min: 0, max: 100 }), control('Vertical focal point (%)', settings, 'focal_y', null, 'range', { min: 0, max: 100 }));
        fields.append(el('p', { class: 'mcb-note' }, Drupal.t('Image share controls height above text or width beside text. A 75% image is supported; text can grow beyond the minimum height to stay readable. Change devices to adjust each crop separately.')), button('Copy this image layout to all sizes', () => change(() => { Object.keys(devices).forEach((name) => { card.responsive[name] = clone(settings); }); })));
        return;
      }
      const rows = el('div', { class: 'mcb-rows' });
      card.rows.forEach((row, index) => {
        const details = el('details', { open: '' }); const summary = el('summary', {}, Drupal.t('Row @number · @text', { '@number': index + 1, '@text': row.cells.map((item) => item.text).join(' / ').slice(0, 45) })); details.append(summary);
        const actions = el('div', { class: 'mcb-actions' }); moves(card.rows, index, actions, rows); details.dataset.sortId = row.id;
        const removeRow = button('Remove row', () => change(() => { if (card.rows.length > 1) card.rows.splice(index, 1); }));
        removeRow.disabled = card.rows.length === 1; actions.append(removeRow);
        const split = el('select', { 'aria-label': Drupal.t('Row columns') });
        Object.entries({ full: 'Full width', halves: '50% / 50%', thirds: 'Three equal columns', 'one-two': '33% / 67%', 'two-one': '67% / 33%' }).forEach(([value, title]) => split.append(el('option', { value }, title))); split.value = row.split;
        split.addEventListener('change', () => change(() => { row.split = split.value; while (row.cells.length < splits[row.split]) row.cells.push(cell()); row.cells.length = splits[row.split]; }));
        details.append(actions, split, check('Stack cells on mobile', row, 'stack_mobile'), check('Divider above row', row, 'divider'), check('Push row toward bottom', row, 'bottom'));
        row.cells.forEach((item, column) => {
          const section = el('fieldset'); section.append(el('legend', {}, Drupal.t('Column @number', { '@number': column + 1 })), control('Element', item, 'type', { heading: 'Heading', text: 'Text', eyebrow: 'Intro label', badge: 'Initials badge', button: 'Link / UT button' }), control('Text', item, 'text', null, 'textarea', { maxlength: item.type === 'text' ? 1200 : item.type === 'badge' ? 8 : item.type === 'button' ? 60 : 160 }), control('Alignment', item, 'align', { left: 'Left', center: 'Center', right: 'Right' }), control('Size', item, 'size', { small: 'Small', medium: 'Medium', large: 'Large' }), control('Font', item, 'font', { sans: 'Libre Franklin', serif: 'Charis SIL' }));
          if (item.type === 'button') section.append(control('Destination', item, 'url', null, 'text', { maxlength: 2048, placeholder: '/about or https://…' }), control('Link style', item, 'style', { link: 'Text link', primary: 'Filled button', secondary: 'Outline button' }));
          details.append(section);
        });
        rows.append(details);
      });
      const newRow = button('Add row', () => change(() => { card.rows.push({ id: id(), split: 'full', stack_mobile: true, divider: false, bottom: false, cells: [cell()] }); })); newRow.disabled = card.rows.length >= 8;
      const duplicate = button('Duplicate card', () => change(() => { const copy = clone(card); copy.id = id(); state.cards.splice(state.cards.indexOf(card) + 1, 0, copy); selected = copy.id; })); duplicate.disabled = state.cards.length >= 12;
      const remove = button('Remove card', () => change(() => { state.cards = state.cards.filter((item) => item !== card); })); remove.disabled = state.cards.length === 1;
      fields.append(rows, newRow, duplicate, remove);
    }
    function drawOrder() {
      order.replaceChildren(); state.cards.forEach((card, index) => {
        const row = el('li'); moves(state.cards, index, row, order);
        const title = card.rows.flatMap((item) => item.cells).find((item) => item.type === 'heading')?.text || 'Untitled card';
        row.append(button(`${index + 1}. ${title}`, () => { selected = card.id; tab = 'content'; render(); }, { 'aria-pressed': String(selected === card.id) })); order.append(row);
      });
    }
    function preview() {
      const collection = el('div', { class: `mcb-collection mhb-hero mcb-gap--${state.gap} mcb-radius--${state.radius}` });
      Object.entries(state.columns).forEach(([name, count]) => collection.style.setProperty(`--mcb-cols-${name}`, Math.max(1, Math.min(4, Number(count) || 1))));
      const grid = el('ul', { class: 'mcb-grid' });
      state.cards.forEach((card) => {
        const image = images[card.image];
        const item = el('li', { class: 'mcb-item' });
        const article = el('article', { class: `mcb-card mcb-scheme--${card.scheme} ${Object.entries(card.responsive).map(([name, settings]) => `mcb-${name}--${settings.layout}`).join(' ')} ${image ? 'mcb-has-image' : ''} ${selected === card.id ? 'mcb-selected' : ''}`, tabindex: '0', role: 'button', 'aria-label': Drupal.t('Edit card @number', { '@number': state.cards.indexOf(card) + 1 }) });
        Object.entries(card.responsive).forEach(([name, settings]) => { Object.entries({ share: `${settings.share}%`, height: `${settings.height}px`, 'image-height': `${settings.height * settings.share / 100}px`, x: `${settings.focal_x}%`, y: `${settings.focal_y}%` }).forEach(([key, value]) => article.style.setProperty(`--mcb-${key}-${name}`, value)); });
        if (image) { const mediaBox = el('div', { class: 'mcb-image' }); mediaBox.append(el('img', { src: image.url, alt: card.decorative ? '' : card.alt })); article.append(mediaBox); }
        const content = el('div', { class: 'mcb-content' });
        card.rows.forEach((row) => {
          const line = el('div', { class: `mcb-content-row mcb-split--${row.split} ${row.stack_mobile ? 'mcb-stack-mobile' : ''} ${row.divider ? 'mcb-divider' : ''} ${row.bottom ? 'mcb-bottom' : ''}` });
          row.cells.forEach((part) => { const wrapper = el('div', { class: `mcb-cell mcb-align--${part.align} mcb-size--${part.size} mcb-font--${part.font}` }); wrapper.append(el(part.type === 'heading' ? (['h2', 'h3', 'h4'].includes(state.heading_level) ? state.heading_level : 'h3') : part.type === 'button' ? 'span' : 'p', { class: part.type === 'button' ? `mcb-link mcb-link--${part.style}` : `mcb-${part.type}` }, part.text)); line.append(wrapper); }); content.append(line);
        });
        const select = () => { selected = card.id; tab = 'content'; render(); fields.querySelector('textarea')?.focus({ preventScroll: true }); };
        article.addEventListener('click', select); article.addEventListener('keydown', (event) => { if (['Enter', ' '].includes(event.key)) { event.preventDefault(); select(); } });
        article.append(content); item.append(article); grid.append(item);
      });
      collection.append(grid); stage.replaceChildren(collection); fit();
    }
    function fit() { const width = devices[device]; stage.style.width = `${width}px`; const scale = Math.min(1, viewport.clientWidth / width); stage.style.transform = `scale(${scale})`; viewport.style.height = `${stage.scrollHeight * scale}px`; caption.textContent = Drupal.t('@widthpx preview · @scale% scale', { '@width': width, '@scale': Math.round(scale * 100) }); }
    function render() { toolbar.querySelectorAll('[data-device]').forEach((node) => node.setAttribute('aria-pressed', String(node.dataset.device === device))); inspect(); preview(); drawOrder(); }
    function readMedia() { try { images = JSON.parse(media.querySelector('[data-card-media]')?.dataset.cardMedia || '{}'); } catch (_) { images = {}; } save(); preview(); if (tab === 'image') inspect(); }
    const resize = new ResizeObserver(fit); resize.observe(viewport); resize.observe(stage);
    const observer = new MutationObserver(readMedia); observer.observe(media, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-card-media'] });
    root._cardCleanup = () => { resize.disconnect(); observer.disconnect(); };
    readMedia(); render(); save();
  }
  Drupal.behaviors.moodyCardBuilder = { attach(context) { once('moody-card-builder', '[data-moody-card-builder]', context).forEach(init); }, detach(context, settings, trigger) { if (trigger === 'unload') once.remove('moody-card-builder', '[data-moody-card-builder]', context).forEach((root) => root._cardCleanup?.()); } };
})(Drupal, once);
