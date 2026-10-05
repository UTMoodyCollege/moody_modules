// Uses the existing local Drupal/Playwright installations; installs nothing.
const { chromium } = require('@playwright/test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const site = process.env.HERO_TEST_SITE;
assert.ok(site, 'Set HERO_TEST_SITE to the local DDEV checkout');
const php = '$ids=\\Drupal::entityQuery("media")->accessCheck(FALSE)->condition("bundle","utexas_image")->range(0,1)->execute(); $b=\\Drupal\\block_content\\Entity\\BlockContent::create(["type"=>"moody_hero","info"=>"Unsaved QA"]); $b->set("field_block_moody_hero",["media"=>reset($ids),"heading"=>"QA"]); $r=$b->get("field_block_moody_hero")->view(["type"=>"moody_hero_1","label"=>"hidden"]); echo (string)\\Drupal::service("renderer")->renderInIsolation($r);';
(async () => {
  const html = execFileSync('ddev', ['exec', '--raw', '--', 'drush', 'php:eval', php], { cwd: site, encoding: 'utf8' });
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  try {
    const page = await browser.newPage();
    await page.setContent('<main id="layout"></main>');
    // Like Layout Builder, insert an unsaved server-rendered fragment over AJAX.
    await page.locator('#layout').evaluate((node, fragment) => node.insertAdjacentHTML('beforeend', fragment), html);
    for (const width of [375, 768, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      const background = await page.locator('.hero-img').evaluate(node => getComputedStyle(node).backgroundImage);
      assert.ok(background.startsWith('url('), `Unsaved image missing at ${width}px`);
    }
    for (const current of ['default', 'moody_hero_5_left']) {
      const options = ['default', 'moody_hero_1_left', 'moody_hero_5_left', 'moody_hero_6', 'moody_hero_6_left'].map(value => `<option value="${value}" ${value === current ? 'selected' : ''}>${value}</option>`).join('');
      await page.setContent(`<form><div><div class="js-form-item-settings-view-mode"><select name="settings[view_mode]">${options}</select></div></div></form>`);
      await page.addScriptTag({ content: fs.readFileSync(path.join(site, 'web/core/assets/vendor/jquery/jquery.min.js'), 'utf8') });
      await page.addScriptTag({ content: fs.readFileSync(path.join(site, 'web/core/assets/vendor/once/once.min.js'), 'utf8') });
      await page.evaluate(() => { window.Drupal = { behaviors: {} }; window.drupalSettings = {}; });
      await page.addScriptTag({ content: fs.readFileSync(path.join(__dirname, '../js/hero-formatters-split.js'), 'utf8') });
      await page.evaluate(() => Drupal.behaviors.moodyHeroCustomSelectors.attach(document, {}));
      assert.equal(await page.locator('.moody-hero-carousel-card[data-style="moody_hero_5"]').count(), 0);
      if (current === 'default') {
        assert.equal(await page.locator('select[name="hero_style"] option[value="moody_hero_5"]').count(), 0);
      } else {
        assert.ok(await page.locator('select[name="hero_style"] option[value="moody_hero_5"]').isDisabled());
        assert.equal(await page.locator('select[name="settings[view_mode]"]').inputValue(), current);
        assert.ok(await page.locator('select[name="anchor_position"]').isDisabled());
        await page.locator('select[name="hero_style"]').selectOption('moody_hero_6');
        assert.equal(await page.locator('select[name="settings[view_mode]"]').inputValue(), 'moody_hero_6_left');
      }
    }
    console.log('PASS: unsaved AJAX Hero image backgrounds at three sizes; Style 5 unavailable for new selections and existing style retained.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
