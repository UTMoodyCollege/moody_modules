// Requires an existing Playwright installation; does not install dependencies.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const { pathToFileURL } = require('node:url');
const path = require('node:path');

(async () => {
  const browser = await chromium.launch({ headless: true, channel: process.env.HERO_BROWSER_CHANNEL || 'chrome' });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(pathToFileURL(path.join(__dirname, 'builder.preview.html')).href);
    const editor = page.locator('[data-moody-hero-builder] [data-hero-editor]');
    const state = async () => JSON.parse(await page.locator('[data-hero-source]').inputValue());
    const choose = async (key, value) => {
      const field = editor.locator(`[data-hero-setting="${key}"]`);
      await field.focus();
      await field.selectOption(value);
    };
    await choose('layout', 'overlay');
    await editor.locator('.mhb-move').focus();
    await page.keyboard.press('ArrowRight');
    assert.equal((await state()).position, 'center');
    await editor.getByRole('button', { name: 'Undo hero change' }).click();
    assert.equal((await state()).position, 'center-left');
    await editor.getByRole('button', { name: 'Redo hero change' }).click();
    assert.equal((await state()).position, 'center');
    await editor.locator('.mhb-drag').first().focus();
    await page.keyboard.press('ArrowDown');
    assert.equal((await state()).elements[0].type, 'text');
    await editor.getByLabel('New element type').selectOption('button');
    await editor.getByRole('button', { name: 'Add element', exact: true }).click();
    await editor.locator('[data-hero-setting="url"]').fill('javascript:alert(1)');
    assert.equal(await editor.locator('.mhb-status').getAttribute('data-state'), 'error');
    await editor.locator('[data-hero-setting="url"]').fill('/about');
    assert.equal(await editor.locator('.mhb-status').getAttribute('data-state'), 'success');
    await editor.getByRole('button', { name: 'Image', exact: true }).click();
    await editor.getByLabel('Image is decorative').uncheck();
    assert.equal(await editor.locator('.mhb-status').getAttribute('data-state'), 'error');
    await editor.locator('[data-hero-setting="image_alt"]').fill('People on campus');
    for (const [device, width] of [['Desktop',1280],['Tablet',768],['Mobile',375]]) {
      await editor.getByRole('button', { name: device, exact: true }).click();
      assert.equal(await editor.locator('.mhb-stage').evaluate(n => n.clientWidth), width);
    }
    // An image arriving through the native widget's AJAX metadata updates preview.
    await page.locator('[data-hero-media]').evaluate(n => {
      n.dataset.heroImageUrl = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800"><rect width="1200" height="800" fill="white"/></svg>');
    });
    await editor.locator('.mhb-stage img').waitFor();
    assert.equal(await editor.locator('.mhb-stage img').getAttribute('alt'), 'People on campus');
    for (const width of [320,375,414,768,1280,1920]) {
      await page.setViewportSize({ width, height: 1000 });
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Editor overflow at ${width}px`);
    }
    assert.deepEqual(errors, []);
    console.log('Hero browser checks passed: editing, keyboard move/reorder, undo/redo, URL/alt errors, media update and responsive previews.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
