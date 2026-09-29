// NODE_PATH=/path/to/node_modules node tests/compact-dialog.cjs
const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require('@playwright/test');
(async () => {
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();
    await page.setContent(`<style>:root{--drupal-displace-offset-top:160px}body{margin:0}.toolbar{position:fixed;inset:0 0 auto;height:160px;background:black;z-index:1250}.ui-dialog-titlebar-close{box-sizing:border-box}</style><div class="toolbar"></div><div class="ui-dialog--lbim"><div class="ui-dialog-titlebar"><span class="ui-dialog-title">Configure block</span><button class="ui-dialog-titlebar-close" aria-label="Close">×</button></div><div id="drupal-lbim-modal"><iframe class="lbim-dialog-iframe" title="Block editor"></iframe></div></div>`);
    await page.addStyleTag({ path: path.join(__dirname, '../css/layout-builder-toolbar.css') });
    await page.evaluate(() => {
      document.body.classList.add('layout-builder-iframe-modal');
      const container = document.createElement('div');
      container.className = 'page-content';
      container.innerHTML = '<form class="layout-builder-configure-block"><div class="form-item form-item--settings-label"><label class="form-item__label">Title</label><input class="form-text"></div><div class="moody-reusable-edit"><a class="button" href="#">Edit this reusable block</a></div></form>';
      document.body.append(container);
    });
    const spacing = await page.evaluate(() => ({
      label: getComputedStyle(document.querySelector('.form-item__label')).marginTop,
      page: getComputedStyle(document.querySelector('.page-content')).marginTop,
      button: document.querySelector('.moody-reusable-edit a').getBoundingClientRect().height,
    }));
    assert.equal(spacing.label, '0px');
    assert.equal(spacing.page, '8px');
    assert(spacing.button >= 44);
    for (const [width, height] of [[1440, 900], [768, 1024], [390, 844], [844, 390]]) {
      await page.setViewportSize({ width, height });
      const result = await page.evaluate(() => {
        const dialog = document.querySelector('.ui-dialog--lbim');
        const rect = dialog.getBoundingClientRect();
        const close = dialog.querySelector('button').getBoundingClientRect();
        return { top: rect.top, bottom: rect.bottom, width: rect.width, title: dialog.firstElementChild.getBoundingClientRect().height, close: [close.width, close.height], onTop: !!document.elementFromPoint(rect.left + 20, 20).closest('.ui-dialog--lbim') };
      });
      assert.equal(result.top, 8);
      assert.equal(result.bottom, height - 8);
      assert(result.width <= width - 16);
      assert(result.title <= 52);
      assert(result.close.every(size => size >= 44));
      assert(result.onTop);
    }
    console.log('Compact dialog: viewport space, toolbar stacking, title height and touch target passed at four sizes.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
