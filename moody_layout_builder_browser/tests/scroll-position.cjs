// NODE_PATH=/path/to/node_modules node tests/scroll-position.cjs
const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require('@playwright/test');

(async () => {
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();
    for (const [width, height] of [[1440, 900], [768, 1024], [390, 844], [844, 390]]) {
      await page.setViewportSize({ width, height });
      await page.setContent('<button id="first">First layout control</button><div style="height:1500px"></div><div id="layout-builder"><div data-layout-block-uuid="block-one"><div class="contextual"><button class="trigger">Block options</button><a href="/layout_builder/update/block/test">Edit</a></div></div><a href="/layout_builder/choose/block/test">Add block</a></div><div style="height:2500px"></div>');
      await page.evaluate(() => {
        window.Drupal = { Ajax: function () {} };
        // Exercise the actual browser focus/scroll effects of Drupal's queue:
        // dialog opening and refocus to the first control after replacement.
        Drupal.Ajax.prototype.success = async function (commands) {
          for (const command of commands) {
            if (['openIframe', 'openDialog'].includes(command.command)) document.querySelector('#first').focus();
            if (command.command === 'insert' && command.selector === '#layout-builder') {
              const layout = document.querySelector('#layout-builder');
              layout.outerHTML = layout.outerHTML;
              setTimeout(() => document.querySelector('#first').focus(), 0);
            }
            if (command.command === 'scrollToBlock') setTimeout(() => window.scrollTo(0, 500), 200);
          }
        };
      });
      // Prove the pre-fix queue loses the editor's position.
      assert(await page.evaluate(async () => {
        window.scrollTo(0, 1450);
        await Drupal.Ajax.prototype.success.call({ url: '/layout_builder/update/block/test' }, [{ command: 'openIframe' }]);
        return window.scrollY < 100;
      }));
      await page.addScriptTag({ path: path.join(__dirname, '../js/layout-builder-scroll.js') });
      for (const type of ['edit', 'add']) {
        const result = await page.evaluate(async (type) => {
          const selector = type === 'edit' ? '.contextual a' : '#layout-builder > a';
          const link = document.querySelector(selector);
          link.addEventListener('click', event => event.preventDefault(), { once: true });
          link.click();
          const ajax = { url: link.getAttribute('href') };
          window.scrollTo(0, 1450);
          await Drupal.Ajax.prototype.success.call(ajax, [{ command: type === 'edit' ? 'openIframe' : 'openDialog' }]);
          const open = window.scrollY;
          await Drupal.Ajax.prototype.success.call(ajax, [{ command: 'insert', selector: '#layout-builder' }, { command: 'scrollToBlock' }]);
          const saved = window.scrollY;
          const focus = document.activeElement.matches(type === 'edit' ? '.contextual .trigger' : '#layout-builder > a');
          // An old delayed command must not pull an editor back after they scroll.
          window.scrollTo(0, 1700);
          await new Promise(resolve => setTimeout(resolve, 250));
          return { open, saved, focus, afterUserScroll: window.scrollY };
        }, type);
        assert.equal(result.open, 1450);
        assert.equal(result.saved, 1450);
        assert(result.focus);
        assert.equal(result.afterUserScroll, 1700);
      }
      assert.equal(await page.evaluate(async () => {
        window.scrollTo(0, 1450);
        await Drupal.Ajax.prototype.success.call({ url: '/unrelated/dialog' }, [{ command: 'openDialog' }]);
        return window.scrollY;
      }), 0, 'Unrelated dialogs retain their original focus behavior');
    }
    console.log('PASS: add/edit opening, rebuild, keyboard focus and subsequent scrolling at four viewport sizes; unrelated dialogs unchanged.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
