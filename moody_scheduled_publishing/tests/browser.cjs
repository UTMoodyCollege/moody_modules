// Run with NODE_PATH pointing to an installed Playwright; no downloaded runtime.
const {chromium} = require('playwright');
const {execFileSync, spawn} = require('node:child_process');
const assert = require('node:assert/strict');
const site = process.env.MOODY_SCHEDULE_QA_SITE_DIR;
if (!site) throw new Error('Set MOODY_SCHEDULE_QA_SITE_DIR to the local DDEV directory.');
const drush = (...args) => execFileSync('ddev', ['drush', ...args], {cwd: site, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe']}).trim();
if (drush('php:eval', 'echo getenv("DDEV_SITENAME") && !getenv("PANTHEON_ENVIRONMENT") ? "local" : "denied";') !== 'local') throw new Error('Local DDEV only.');
const title = `Scheduled Publishing QA ${Date.now()}`;
let browser;
let nid;
(async () => {
  try {
    browser = await chromium.launch({channel: 'chrome', headless: true});
    const page = await browser.newPage({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 1000}});
    const login = drush('user:login', '--uid=1', '--no-browser', '--uri=https://2moody-core.ddev.site').match(/https:\/\/\S+/)[0];
    await page.goto(login);
    await page.goto('https://2moody-core.ddev.site/node/add/moody_standard_page');
    await page.locator('input[name="title[0][value]"]').fill(title);
    for (const select of await page.locator('select[required]').all()) {
      const value = await select.locator('option').evaluateAll(options => options.find(o => o.value && o.value !== '_none')?.value);
      if (value) await select.selectOption(value);
    }
    await page.getByLabel('Published', {exact: true}).uncheck();
    await page.locator('details').filter({has: page.locator('input[name="moody_schedule[enabled]"]')}).locator('summary').click();
    await page.locator('input[name="moody_schedule[enabled]"]').check();
    await page.locator('input[name="moody_schedule[at][date]"]').fill('2030-01-15');
    await page.locator('input[name="moody_schedule[at][time]"]').fill('12:00:00');
    await page.getByRole('button', {name: 'Save', exact: true}).click();
    await page.waitForLoadState('networkidle');
    nid = Number(drush('php:eval', `echo array_key_first(\\Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['title' => '${title}'])) ?? 0;`));
    assert(nid > 0, (await page.locator('body').innerText()).slice(-2500));
    const state = () => JSON.parse(drush('php:eval', `echo json_encode(\\Drupal::service('moody_scheduled_publishing.scheduler')->get(${nid}));`));
    assert.equal(state().state, 'pending', 'Browser save persists schedule');
    await page.goto('https://2moody-core.ddev.site/admin/content/scheduled-publishing');
    assert((await page.locator('body').innerText()).includes(title), 'Dashboard lists scheduled node');
    await page.screenshot({path: '/tmp/moody-scheduled-publishing-dashboard.png', fullPage: true});
    await page.goto(`https://2moody-core.ddev.site/node/${nid}/edit`);
    assert(await page.locator('input[name="moody_schedule[enabled]"]').isChecked(), 'Schedule survives reload');
    await page.screenshot({path: '/tmp/moody-scheduled-publishing-edit.png', fullPage: true});
    await page.locator('input[name="moody_schedule[enabled]"]').uncheck();
    await page.getByRole('button', {name: 'Save', exact: true}).click();
    await page.waitForLoadState('networkidle');
    assert.equal(state().state, 'cancelled', 'Browser cancellation persists');
    drush('php:eval', `\\Drupal::service('moody_scheduled_publishing.scheduler')->schedule(${nid}, time() + 3600, \\Drupal\\user\\Entity\\User::load(1)); \\Drupal::database()->update('moody_publish_schedule')->fields(['publish_at' => time() - 5])->condition('nid', ${nid})->execute();`);
    const receipt = JSON.parse(drush('moody-scheduled-publishing:process', '--prototype'));
    assert(receipt.published.includes(nid), 'Actual Drush command publishes browser-created content');
    assert.equal(state().state, 'published');
    assert(!JSON.parse(drush('moody-scheduled-publishing:process', '--prototype')).published.includes(nid), 'Actual Drush rerun is idempotent');
    const holder = spawn('ddev', ['drush', 'php:eval', '$lock = \\Drupal::lock(); if (!$lock->acquire("moody_scheduled_publishing", 20)) { throw new \\RuntimeException("Lock unavailable"); } echo "LOCK_HELD"; fflush(STDOUT); sleep(8); $lock->release("moody_scheduled_publishing");'], {cwd: site, stdio: ['ignore', 'pipe', 'pipe']});
    const released = new Promise((resolve, reject) => { holder.on('error', reject); holder.on('exit', code => code === 0 ? resolve() : reject(new Error('Lock holder failed'))); });
    await new Promise((resolve, reject) => { holder.stdout.on('data', data => { if (data.toString().includes('LOCK_HELD')) resolve(); }); holder.on('error', reject); holder.on('exit', () => reject(new Error('Lock holder exited before readiness'))); });
    assert.throws(() => drush('moody-scheduled-publishing:process', '--prototype'), /Publishing is running/, 'Concurrent processor refuses existing lock');
    await released;
    console.log('Passed browser form/dashboard checks, actual Drush publication, duplicate-run and independent-process locking checks.');
  } finally {
    if (!nid) nid = Number(drush('php:eval', `echo array_key_first(\\Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['title' => '${title}'])) ?? 0;`));
    if (nid) drush('php:eval', `$cleanup = function () { $n = \\Drupal::entityTypeManager()->getStorage('node')->load(${nid}); if ($n) { $n->delete(); } }; if (\\Drupal::hasService('trash.manager')) { \\Drupal::service('trash.manager')->executeInTrashContext('ignore', $cleanup); } else { $cleanup(); } foreach (['moody_publish_schedule', 'moody_publish_event'] as $table) { \\Drupal::database()->delete($table)->condition('nid', ${nid})->execute(); }`);
    await browser?.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
