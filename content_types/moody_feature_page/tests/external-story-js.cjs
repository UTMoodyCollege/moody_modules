// Run with: node tests/external-story-js.cjs
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(`${__dirname}/../js/external-story.js`, 'utf8');
function fixture(hidden = false) {
  let timer;
  let destination;
  const events = {};
  const elements = {
    '[data-redirect-controls]': { hidden: true },
    '[data-stop-redirect]': { addEventListener: (name, fn) => { events[name] = fn; } },
    '[data-redirect-status]': {},
    '[data-story-link]': { href: 'https://news.utexas.edu/story' },
  };
  const notice = { querySelector: (selector) => elements[selector] };
  const document = { hidden, addEventListener: (name, fn) => { events[name] = fn; } };
  const Drupal = { behaviors: {}, t: (text) => text };
  vm.runInNewContext(source, { Drupal, once: () => [notice], document, window: {
    setTimeout: (fn, delay) => { assert.equal(delay, 20000); timer = fn; return 1; },
    clearTimeout: () => { timer = undefined; },
    location: { replace: (url) => { destination = url; } },
    addEventListener: (name, fn) => { events[name] = fn; },
  } });
  Drupal.behaviors.moodyExternalStory.attach({});
  assert.equal(elements['[data-redirect-controls]'].hidden, false);
  return { events, document, fire: () => timer?.(), destination: () => destination };
}
const automatic = fixture();
automatic.fire();
assert.equal(automatic.destination(), 'https://news.utexas.edu/story');
for (const event of ['click', 'pagehide', 'visibilitychange']) {
  const stopped = fixture();
  stopped.document.hidden = true;
  stopped.events[event]();
  stopped.fire();
  assert.equal(stopped.destination(), undefined);
}
const background = fixture(true);
background.fire();
assert.equal(background.destination(), undefined);
console.log('PASS: timed forwarding, stop control, page departure and hidden-tab cancellation.');
