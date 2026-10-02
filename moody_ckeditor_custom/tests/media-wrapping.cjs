// NODE_PATH=/path/to/existing/node_modules node tests/media-wrapping.cjs
const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require('@playwright/test');

(async () => {
  const browser = await chromium.launch({headless: true});
  try {
    const page = await browser.newPage();
    const image = '<img width="2400" height="1601" alt="Test image" src="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%222400%22 height=%221601%22/%3E">';
    for (const caption of [true, false]) {
      // Centered legacy media must retain its native layout, not a new width cap.
      await page.setViewportSize({width: 1200, height: 900});
      await page.setContent(`<style>body { margin: 20px; } img { max-width: 100%; height: auto; }
        .align-center { margin: 0 auto; } figure { display: table; }
        figcaption { display: table-caption; caption-side: bottom; }</style>
        <${caption ? 'figure' : 'div'} class="align-center"><div class="field--type-image">${image}</div>${caption ? '<figcaption>Caption</figcaption>' : ''}</${caption ? 'figure' : 'div'}>`);
      const centered = page.locator('.align-center');
      const nativeBox = await centered.boundingBox();
      const nativeDisplay = await centered.evaluate(el => getComputedStyle(el).display);
      await page.addStyleTag({path: path.join(__dirname, '../css/moody-editor-utility-styles.css')});
      assert.deepEqual(await centered.boundingBox(), nativeBox, 'Legacy centered media geometry must stay unchanged');
      assert.equal(await centered.evaluate(el => getComputedStyle(el).display), nativeDisplay);
      for (const align of ['left', 'right']) {
        const tag = caption ? 'figure' : 'div';
        await page.setContent(`<style>
          body { margin: 20px; } img { max-width: 100%; height: auto; }
          .align-left { float: left; } .align-right { float: right; }
          figure { display: table; margin: 0; } figcaption { display: table-caption; caption-side: bottom; }
          .ck-content .drupal-media-style-align-left { float: left; max-width: 50%; }
        </style><main><${tag} class="align-${align}"><div><div class="field--type-image">${image}</div></div>${caption ? '<figcaption>Image caption</figcaption>' : ''}</${tag}><p><span id="text">Following text should wrap alongside the image.</span></p></main>
        <div class="ck-content"><div class="drupal-media-style-align-left">${image}</div></div>`);
        await page.setViewportSize({width: 1200, height: 900});
        const wrapper = page.locator(`main > .align-${align}`);
        assert((await wrapper.boundingBox()).width > 580, 'Fixture must reproduce the original oversized wrapper');
        await page.addStyleTag({path: path.join(__dirname, '../css/moody-editor-utility-styles.css')});
        for (const width of [1200, 768, 767, 375, 320]) {
          await page.setViewportSize({width, height: 900});
          const box = await wrapper.boundingBox();
          const text = await page.locator('#text').boundingBox();
          assert(box.width <= (width - 40) * (width >= 768 ? 0.5 : 1) + 1);
          assert.equal(await wrapper.evaluate(el => getComputedStyle(el).float), width >= 768 ? align : 'none');
          if (width >= 768) assert(text.y < box.y + box.height, 'Text must wrap next to image');
          else assert(text.y >= box.y + box.height, 'Mobile text must follow image');
          if (caption) assert.equal(await page.locator('figcaption').evaluate(el => getComputedStyle(el).display), 'block');
          assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No horizontal overflow');
          assert.equal(await page.locator('.drupal-media-style-align-left').evaluate(el => getComputedStyle(el).float), width >= 768 ? 'left' : 'none');
        }
      }
    }
    console.log('Captioned/uncaptioned left/right media wrap at desktop/tablet and stack on mobile.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
