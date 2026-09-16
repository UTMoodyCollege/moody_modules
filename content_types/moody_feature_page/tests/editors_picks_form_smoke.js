// Run with node; no browser or extra packages required.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const Drupal = { behaviors: {} };
const handlers = {};
const input = {
  value: '"Sales team ""Rookie of the year"" (2237)"',
  addEventListener: (name, fn) => { handlers[name] = fn; },
  dispatchEvent: () => {},
  focus: () => {},
};
const recent = {
  value: '',
  selectedOptions: [{ textContent: 'A story, with "quotes"' }],
  addEventListener: (name, fn) => { handlers.recentChange = fn; },
};
const row = { querySelector: (selector) => selector.endsWith('-node') ? input : recent };
vm.runInNewContext(fs.readFileSync(`${__dirname}/../js/editors-picks-form.js`, 'utf8'), {
  Drupal, jQuery: () => ({ on: (events, fn) => { handlers.clean = fn; } }),
  once: () => [row], Event: class {},
});
Drupal.behaviors.moodyEditorsPicksForm.attach({});
assert.equal(input.value, 'Sales team "Rookie of the year" (2237)');
input.value = '"Title, with comma (19)"';
handlers.clean();
assert.equal(input.value, 'Title, with comma (19)');
input.value = '"Actual quoted title" (19)';
handlers.clean();
assert.equal(input.value, '"Actual quoted title" (19)');
recent.value = '42';
handlers.recentChange();
assert.equal(input.value, 'A story, with "quotes" (42)');
assert.equal(recent.value, '');
recent.value = '43';
handlers.input();
assert.equal(recent.value, '');
console.log('Editors Picks form behavior passed.');
