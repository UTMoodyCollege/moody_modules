// NODE_PATH=/path/to/node_modules node tests/readable-text.cjs /path/to/drupal/web
const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require('@playwright/test');

(async () => {
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.setContent('<div id="editor"></div>');
    for (const name of ['ckeditor5-dll', 'editor-classic', 'essentials', 'html-support']) {
      await page.addScriptTag({ path: path.join(process.argv[2], `core/assets/vendor/ckeditor5/${name}/${name}.js`) });
    }
    await page.addScriptTag({ path: path.join(__dirname, '../js/build/moodyReadableText.js') });
    await page.evaluate(async () => {
      window.editor = await CKEditor5.editorClassic.ClassicEditor.create(document.querySelector('#editor'), {
        licenseKey: 'GPL',
        plugins: [CKEditor5.essentials.Essentials, CKEditor5.paragraph.Paragraph, CKEditor5.htmlSupport.GeneralHtmlSupport, CKEditor5.moodyReadableText.MoodyReadableText],
        toolbar: ['moodyReadableText'],
        htmlSupport: { allow: [{ name: /.*/, attributes: true, classes: true, styles: true }] },
      });
    });
    const fixture = '<div class="moody-readable-text moody-readable-text--measure-narrow moody-readable-text--pad-small"><div class="moody-readable-text moody-readable-text--measure-narrow moody-readable-text--pad-large" id="keep"><p>[nice_letter lead="M"]Keep this text[/nice_letter]</p><drupal-media data-entity-uuid="keep-media" data-caption="Keep caption">&nbsp;</drupal-media></div><p>Trailing text</p></div>';
    await page.evaluate(html => {
      editor.setData(html);
      editor.model.change(writer => writer.setSelection(editor.model.document.getRoot().getChild(0).getChild(0).getChild(0), 3));
    }, fixture);
    const before = await page.evaluate(() => editor.getData());
    await page.getByRole('button', { name: 'Readable Text Block', exact: true }).click();
    assert.equal(await page.locator('[name="measure"]').inputValue(), 'narrow');
    assert.equal(await page.locator('[name="gutter"]').inputValue(), 'large');
    await page.getByRole('button', { name: 'Cancel', exact: true }).click();
    await page.locator('dialog').waitFor({ state: 'detached' });
    assert.equal(await page.evaluate(() => editor.getData()), before);
    await page.getByRole('button', { name: 'Readable Text Block', exact: true }).click();
    await page.locator('[name="gutter"]').selectOption('none');
    await page.getByRole('button', { name: 'Apply', exact: true }).click();
    await page.locator('dialog').waitFor({ state: 'detached' });
    const after = await page.evaluate(() => editor.getData());
    assert.equal((after.match(/moody-readable-text--measure/g) || []).length, 1);
    assert(after.includes('moody-readable-text--pad-none'));
    for (const text of ['keep-media', 'Keep caption', 'Trailing text', 'id="keep"', '[nice_letter']) assert(after.includes(text), text);
    await page.evaluate(() => editor.execute('undo'));
    assert.equal(await page.evaluate(() => editor.getData()), before);
    await page.evaluate(() => editor.execute('redo'));
    assert.equal(await page.evaluate(() => editor.getData()), after);
    await page.getByRole('button', { name: 'Readable Text Block', exact: true }).click();
    assert.equal(await page.locator('[name="gutter"]').inputValue(), 'none');
    await page.getByRole('button', { name: 'Apply', exact: true }).click();
    await page.locator('dialog').waitFor({ state: 'detached' });
    assert.equal(await page.evaluate(() => editor.getData()), after);
    for (const collapsed of [false, true]) {
      await page.evaluate(collapsed => {
        editor.setData('<p>New readable text</p>');
        editor.model.change(writer => {
          const paragraph = editor.model.document.getRoot().getChild(0);
          writer.setSelection(collapsed ? writer.createPositionAt(paragraph, 0) : writer.createRangeIn(paragraph));
        });
      }, collapsed);
      const original = await page.evaluate(() => editor.getData());
      await page.getByRole('button', { name: 'Readable Text Block', exact: true }).click();
      assert.equal(await page.locator('[name="measure"]').inputValue(), 'default');
      assert.equal(await page.locator('[name="gutter"]').inputValue(), 'medium');
      await page.getByRole('button', { name: 'Apply', exact: true }).click();
      await page.locator('dialog').waitFor({ state: 'detached' });
      assert.deepEqual(errors, [], 'Applying a new wrapper must not throw');
      const inserted = await page.evaluate(() => editor.getData());
      assert.equal((inserted.match(/moody-readable-text--measure/g) || []).length, 1);
      assert(inserted.includes('New readable text'));
      await page.evaluate(() => editor.execute('undo'));
      assert.equal(await page.evaluate(() => editor.getData()), original);
      await page.evaluate(() => editor.execute('redo'));
      assert.equal(await page.evaluate(() => editor.getData()), inserted);
    }
    console.log('Readable Text: new insertion, selection wrapping, existing settings, cancel, nested repair, content preservation, undo/redo, and repeat editing passed.');
  }
  finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
