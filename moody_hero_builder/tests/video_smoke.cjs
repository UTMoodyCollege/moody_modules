const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
for (const mode of ['desktop', 'mobile', 'reduced', 'save-data', 'hidden']) {
  let frame = null;
  const listeners = {};
  const toggle = { hidden: true, setAttribute() {}, addEventListener(name, fn) { listeners[name] = fn; } };
  const media = { dataset: { heroVideo: 'https://player.vimeo.com/video/76979871?background=1' }, querySelector: () => toggle, clientWidth: 1200, clientHeight: 600, append(node) { frame = node; } };
  const reduced = { matches: mode === 'reduced', addEventListener(name, fn) { listeners.preference = fn; }, removeEventListener() {} };
  const document = { hidden: mode === 'hidden', createElement() { return { style: {}, setAttribute() {}, remove() { frame = null; } }; }, addEventListener(name, fn) { listeners[name] = fn; }, removeEventListener() {} };
  const Drupal = { behaviors: {}, t: value => value };
  const once = () => [media]; once.remove = () => [media];
  vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../js/video.js'), 'utf8'), { Drupal, once, document, URL, navigator: { connection: { saveData: mode === 'save-data' } }, matchMedia: query => query.includes('reduced-motion') ? reduced : { matches: mode !== 'mobile' }, ResizeObserver: class { observe() {} disconnect() {} } });
  Drupal.behaviors.moodyHeroVideo.attach({});
  assert.equal(Boolean(frame), mode === 'desktop');
  assert.equal(toggle.hidden, false);
  if (!frame) listeners.click();
  assert.equal(frame.allow, 'autoplay');
  assert.equal(frame.tabIndex, -1);
  listeners.click(); assert.equal(frame, null);
  listeners.click(); document.hidden = true; listeners.visibilitychange(); assert.equal(frame, null);
  listeners.click(); reduced.matches = true; listeners.preference(); assert.equal(frame, null);
  Drupal.behaviors.moodyHeroVideo.detach({}, {}, 'unload');
}
console.log('PASS: video autoplay policy, explicit playback, pause, reduced motion, visibility and cleanup.');
