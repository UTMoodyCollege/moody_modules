// NODE_PATH=/path/to/node_modules node tests/responsive-utilities.cjs
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('@playwright/test');
const root = path.join(__dirname, '..');
(async () => {
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();
    await page.setContent('<style>body{margin:0}*{box-sizing:border-box}</style><div id="grid" class="ut-display-grid ut-cols-1 sm:ut-cols-2 md:ut-cols-3 lg:ut-cols-4 xl:ut-cols-6 ut-gap-4 ut-p-4 md:ut-p-8"><div>A</div><div>B</div><div>C</div></div><div id="split" class="ut-display-grid ut-cols-1 lg:ut-split-one-three"><div>A</div><div>B</div></div><div id="aligned" class="ut-w-full md:ut-w-half ut-ml-auto ut-text-start lg:ut-text-end">Text</div><div id="position" class="ut-position-relative" style="height:100px"><div id="badge" class="ut-position-static md:ut-position-absolute md:ut-top-0 md:ut-right-0">Badge</div></div><img id="image" alt="" class="ut-w-full ut-aspect-wide ut-object-cover ut-object-left-top lg:ut-object-right-bottom">');
    await page.addStyleTag({ path: path.join(root, 'css/moody-responsive-utilities.css') });
    for (const [width, columns] of [[375, 1], [575, 1], [576, 2], [767, 2], [768, 3], [991, 3], [992, 4], [1199, 4], [1200, 6], [1600, 6]]) {
      await page.setViewportSize({ width, height: 900 });
      const values = await page.evaluate(() => {
        const style = id => getComputedStyle(document.getElementById(id));
        return { columns: style('grid').gridTemplateColumns.split(' ').length, padding: style('grid').paddingLeft, gap: style('grid').gap, width: parseFloat(style('aligned').width), align: style('aligned').textAlign, position: style('badge').position, image: style('image').objectPosition, overflow: document.documentElement.scrollWidth > innerWidth };
      });
      assert.equal(values.columns, columns, `columns at ${width}`);
      assert.equal(values.padding, width >= 768 ? '32px' : '16px');
      assert.equal(values.gap, '16px');
      assert.equal(values.width, width >= 768 ? width / 2 : width);
      assert.equal(values.align, width >= 992 ? 'end' : 'start');
      assert.equal(values.position, width >= 768 ? 'absolute' : 'static');
      assert.equal(values.image, width >= 992 ? '100% 100%' : '0% 0%');
      assert.equal(values.overflow, false);
    }
    const classes = JSON.parse(fs.readFileSync(path.join(root, 'utility-classes.json')));
    assert.equal(new Set(classes).size, classes.length);
    assert(!classes.some(name => /radius-(?!none)|turquoise|teal|rounded/.test(name)));
    await page.setContent(fs.readFileSync(path.join(root, 'utility-guide.html'), 'utf8'));
    assert.equal(await page.locator('#moody-responsive-utilities').count(), 1);
    const used = await page.locator('[class]').evaluateAll(nodes => nodes.flatMap(node => [...node.classList]));
    for (const name of used) assert(classes.includes(name) || name === 'ut-cta-link', name);
    console.log(`Responsive utilities passed: 10 viewport sizes, layout, alignment, spacing, positioning, image crop, no overflow, guide/catalog parity (${classes.length} classes).`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
