// With Playwright installed: node tests/flush-images.cjs [public Moody page URL]
const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({executablePath: process.env.CHROME_PATH || undefined});
  try {
    const page = await browser.newPage();
    await page.goto(process.argv[2] || 'https://moody.utexas.edu/', {waitUntil: 'domcontentloaded'});
    await page.addStyleTag({path: path.join(__dirname, '../css/moody-image-gallery.css')});
    // Keep the real site's theme CSS; replace content only with both gallery layouts.
    await page.evaluate(() => {
      document.body.innerHTML = ['legacy', 'mosaic'].map(layout => `
        <section class="moody-image-gallery moody-image-gallery--${layout}" style="--moody-image-gallery-gap:0rem">
          <div class="moody-image-gallery__grid"><div class="moody-image-gallery__group"><div class="moody-image-gallery__group-top">
            ${[0, 1].map(() => '<button type="button" class="moody-image-gallery__tile"><div class="moody-image-gallery__image-wrap">Image</div></button>').join('')}
          </div></div></div>
        </section>`).join('');
    });
    for (const width of [375, 768, 1280]) {
      await page.setViewportSize({width, height: 900});
      for (const tile of await page.locator('.moody-image-gallery__tile').all()) {
        for (const state of ['normal', 'hover', 'focus', 'active']) {
          await page.mouse.move(0, 0);
          await tile.evaluate(el => el.blur());
          if (state === 'hover' || state === 'active') await tile.hover();
          if (state === 'focus') await tile.focus();
          if (state === 'active') await page.mouse.down();
          await page.waitForTimeout(400);
          const style = await tile.evaluate(el => {
            const s = getComputedStyle(el);
            return [s.padding, s.margin, s.borderWidth, s.borderRadius, s.backgroundColor];
          });
          assert.deepEqual(style, ['0px', '0px', '0px', '0px', 'rgba(0, 0, 0, 0)'], `${width}px ${state}`);
          if (state === 'active') await page.mouse.up();
        }
      }
      for (const gap of ['0', '0.25', '0.75', '1.25', '1.75']) {
        const actual = await page.locator('.moody-image-gallery').first().evaluate((el, gap) => {
          el.style.setProperty('--moody-image-gallery-gap', `${gap}rem`);
          return parseFloat(getComputedStyle(el.querySelector('.moody-image-gallery__grid')).gap) / parseFloat(getComputedStyle(document.documentElement).fontSize);
        }, gap);
        assert.equal(actual, Number(gap));
      }
      for (const grid of await page.locator('.moody-image-gallery__grid').all()) {
        await grid.evaluate(el => { el.closest('section').style.backgroundColor = '#bf5700'; });
        assert.equal(await grid.evaluate(el => getComputedStyle(el).backgroundColor), 'rgba(0, 0, 0, 0)');
        await grid.evaluate(el => el.classList.add('moody-image-gallery__grid--white-gutters'));
        assert.equal(await grid.evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(255, 255, 255)');
        await grid.evaluate(el => el.classList.remove('moody-image-gallery__grid--white-gutters'));
      }
    }
    console.log('PASS: gallery tiles are unframed in all interaction states; gutter options preserved.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
