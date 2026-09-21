const assert = require('node:assert/strict');
const path = require('node:path');
const {chromium} = require('playwright');
(async () => {
  const browser = await chromium.launch({executablePath: process.env.CHROME_PATH || undefined});
  try {
    const page = await browser.newPage({viewport: {width: 900, height: 900}});
    const fields = ['mobile', 'tablet', 'desktop'].map(device => `<fieldset data-reveal-device-fields="${device}">${['title', 'body'].map(element =>
      Object.entries({x:[10,0,90],y:[element === 'title' ? 20 : 55,0,90],width:[80,10,100],size:[element === 'title' ? 32 : 20,16,120]}).map(([key, [v,min,max]]) => `<input type="number" min="${min}" max="${max}" value="${v}" data-reveal-field="${device}:${element}:${key}">`).join('') + `<select data-reveal-field="${device}:${element}:align"><option>left</option><option>center</option><option>right</option></select>`).join('')}</fieldset>`).join('');
    await page.setContent(`<fieldset><input name="slides[0][title]" value="Speech, Language, and Hearing Sciences News"><select name="slides[0][title_display]"><option>overlay</option><option>inline</option></select><textarea name="slides[0][body][value]">Independent subheading text</textarea><details open data-reveal-editor><summary>Visual layout</summary><input type="checkbox" checked><div data-reveal-preview></div>${fields}</details></fieldset>`);
    await page.addStyleTag({path:path.join(__dirname,'../css/moody-scroll-reveal-media.admin.css')});
    await page.evaluate(() => { window.Drupal = {behaviors:{},t:s=>s}; window.once = (id,selector,context) => Array.from(context.querySelectorAll(selector)); });
    await page.addScriptTag({path:path.join(__dirname,'../js/moody-scroll-reveal-media.admin.js')});
    await page.evaluate(() => Drupal.behaviors.moodyScrollRevealMediaAdmin.attach(document));
    const input = key => page.locator(`[data-reveal-field="${key}"]`);
    const heading = page.locator('.reveal-editor-box--title');
    await heading.focus(); await page.keyboard.press('ArrowDown');
    assert.equal(await input('mobile:title:y').inputValue(), '21');
    assert.equal(await input('mobile:body:y').inputValue(), '55');
    await page.locator('[data-device="desktop"]').click();
    assert.equal(await input('desktop:title:y').inputValue(), '20');
    const handle = heading.locator('button');
    await handle.focus(); await page.keyboard.press('ArrowLeft');
    assert.equal(await input('desktop:title:width').inputValue(), '79');
    await heading.scrollIntoViewIfNeeded();
    const rect = await heading.boundingBox();
    await page.mouse.move(rect.x + 10, rect.y + 10); await page.mouse.down(); await page.mouse.move(rect.x + 35, rect.y + 35); await page.mouse.up();
    assert(Number(await input('desktop:title:y').inputValue()) > 20);
    await page.locator('[data-device="mobile"]').click();
    assert.equal(await input('mobile:title:y').inputValue(), '21');
    for (const width of [320,375,414,768]) {
      await page.setViewportSize({width,height:900}); await page.waitForTimeout(100);
      assert(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth), `Overflow at ${width}`);
    }
    console.log('PASS: independent device/element values, keyboard resizing, pointer movement and narrow previews.');
  } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
