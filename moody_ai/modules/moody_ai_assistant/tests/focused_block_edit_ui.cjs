// Run with the existing Playwright runtime; uses an isolated headless Chrome.
// No Drupal authentication, page writes, uploads, or model requests.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.setContent(`
      <main><div id="layout-builder">
        <div class="layout-builder-block" data-layout-block-uuid="target"></div>
        <div class="layout-builder-block" data-layout-block-uuid="unsupported"></div>
      </div></main>
      <div class="ai-moody-assistant">
        <button class="ai-moody-assistant__toggle" aria-expanded="false">AI</button>
        <div class="ai-moody-assistant__panel" hidden>
          <div class="ai-moody-assistant__form"><form>
            <input data-ai-assistant-edit-component name="edit_component_uuid">
            <input data-ai-assistant-selected-block-input>
            <textarea data-ai-assistant-composer-source></textarea>
            <div data-ai-assistant-composer-shell data-ai-assistant-selected-blocks>
              <div data-ai-assistant-selected-block-list></div>
              <div contenteditable="true" data-ai-assistant-composer-editor></div>
            </div>
            <input type="file" name="attachments[]">
            <div data-ai-assistant-block-picker>
              <button type="button" data-ai-assistant-block-ref data-ai-assistant-block-ref-id="target"
                data-ai-assistant-block-ref-label="Target block" data-ai-assistant-block-ref-mode="edit"
                data-ai-assistant-block-ref-can-edit="true" data-ai-assistant-block-ref-block-type="basic">Target</button>
              <button type="button" data-ai-assistant-block-ref data-ai-assistant-block-ref-plugin-id="inline_block:basic"
                data-ai-assistant-block-ref-label="New block">New</button>
            </div>
          </form></div>
        </div>
      </div>`);
    await page.evaluate(() => {
      window.Drupal = { behaviors: {}, announce: message => { window.lastAnnouncement = message; } };
      window.drupalSettings = {};
      window.once = () => [];
    });
    const source = fs.readFileSync(path.join(__dirname, '../js/moody-ai-assistant.js'), 'utf8');
    await page.addScriptTag({ content: source.replace('Drupal.behaviors.moodyAiAssistant =', 'window.bindPickerForTest = bindBlockReferencePicker; Drupal.behaviors.moodyAiAssistant =') });
    await page.evaluate(() => {
      window.picker = window.bindPickerForTest(document.querySelector('.ai-moody-assistant'));
      // Reproduce asynchronously loaded Drupal contextual links.
      document.querySelectorAll('.layout-builder-block').forEach(block => {
        block.innerHTML = '<div class="contextual"><ul class="contextual-links"><li><a href="/layout_builder/update/block/overrides/node.1/0/content/target">Edit</a></li><li><a href="#convert">Convert to Reusable Block</a></li></ul></div>';
      });
    });
    const link = page.getByText('Edit with AI', { exact: true });
    await link.waitFor();
    assert.equal(await link.count(), 1, 'Unsupported blocks must not offer an incompatible AI editor.');
    assert.deepEqual(await page.locator('[data-layout-block-uuid="target"] .contextual-links a').allTextContents(), ['Edit', 'Edit with AI', 'Convert to Reusable Block']);
    await link.focus();
    await page.keyboard.press('Enter');
    assert.equal(await page.locator('.ai-moody-assistant__toggle').getAttribute('aria-expanded'), 'true');
    assert.equal(await page.locator('[data-ai-assistant-edit-component]').inputValue(), 'target');
    assert.equal(await page.locator('[data-ai-assistant-composer-editor]').textContent(), '');
    assert.equal(await page.locator('[data-ai-assistant-composer-editor]').evaluate(el => el === document.activeElement), true);
    assert.match(await page.locator('[data-ai-assistant-selected-block-list]').textContent(), /Edit only this block/);
    assert.equal(await page.locator('input[type="file"]').count(), 1, 'Existing attachments interface was lost.');
    await page.getByRole('button', { name: 'New', exact: true }).click();
    assert.equal(await page.evaluate(() => window.picker.getSelected().length), 1, 'Focused scope widened.');
    await page.evaluate(() => window.picker.clear(true));
    assert.equal(await page.locator('[data-ai-assistant-edit-component]').inputValue(), 'target', 'Follow-up scope lost.');
    await page.getByRole('button', { name: 'Exit focused editing of Target block' }).click();
    assert.equal(await page.locator('[data-ai-assistant-edit-component]').inputValue(), '');
    await page.getByRole('button', { name: 'New', exact: true }).click();
    assert.equal(await page.evaluate(() => window.picker.getSelected()[0].selectionMode), 'new');
    await link.click();
    assert.equal(await page.evaluate(() => window.picker.getSelected()[0].uuid), 'target', 'Old selections were not replaced.');
    await page.evaluate(() => document.querySelector('#layout-builder').appendChild(document.createElement('span')));
    assert.equal(await link.count(), 1, 'Contextual link duplicated.');
    await page.evaluate(() => {
      const block = document.createElement('div');
      block.className = 'layout-builder-block';
      block.dataset.layoutBlockUuid = 'later';
      block.dataset.aiAssistantEditBlockType = 'basic';
      block.dataset.aiAssistantEditBlockLabel = 'Later block';
      block.innerHTML = '<ul class="contextual-links"><li><a href="/layout_builder/update/block/overrides/node.1/0/content/later">Edit</a></li></ul>';
      document.querySelector('#layout-builder').appendChild(block);
    });
    await page.locator('[data-ai-assistant-contextual-edit="later"]').click();
    assert.equal(await page.locator('[data-ai-assistant-edit-component]').inputValue(), 'later', 'AJAX-added placement missing from focused editor.');
    assert.deepEqual(errors, []);
    console.log('PASS: contextual order, async insertion, keyboard activation, focused composer, attachments, explicit exit, repeat edits, and no duplicate links.');
  }
  finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
