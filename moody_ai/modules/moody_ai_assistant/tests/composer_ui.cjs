const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const siteDir = process.env.MOODY_AI_QA_SITE_DIR;
if (!siteDir) throw new Error('Set MOODY_AI_QA_SITE_DIR to the local DDEV site checkout.');
(async () => {
  const login = execFileSync('ddev', ['drush', 'user:login', '--uid=1', '--no-browser', '--uri=https://2moody-core.ddev.site'], {cwd:siteDir, encoding:'utf8', stdio:['ignore','pipe','pipe']}).trim();
  const url = login.split('\n').find(line => line.startsWith('https://'));
  if (!url) throw new Error('Local login URL was not returned.');
  const browser = await chromium.launch({channel:'chrome',headless:true});
  let fixture;
  try {
    fixture = JSON.parse(execFileSync('ddev', ['drush', 'php:eval', '$b=\\Drupal\\block_content\\Entity\\BlockContent::create(["type"=>"basic","info"=>"Focused edit QA","reusable"=>FALSE,"body"=>["value"=>"<p>Local focused editing preview.</p>","format"=>"flex_html"]]); $b->save(); $n=\\Drupal\\node\\Entity\\Node::create(["type"=>"moody_standard_page","title"=>"Disposable Edit with AI browser QA","status"=>0,"uid"=>1]); $section=new \\Drupal\\layout_builder\\Section("layout_onecol"); $section->appendComponent(new \\Drupal\\layout_builder\\SectionComponent(\\Drupal::service("uuid")->generate(),"content",["id"=>"inline_block:basic","block_revision_id"=>$b->getRevisionId(),"label"=>"Focused edit QA","label_display"=>FALSE])); $n->set("layout_builder__layout",[$section]); $n->save(); print json_encode(["node"=>(int)$n->id(),"block"=>(int)$b->id()]);'], {cwd:siteDir,encoding:'utf8',stdio:['ignore','pipe','pipe']}));
    const page = await browser.newPage({ignoreHTTPSErrors:true,viewport:{width:1440,height:1100}});
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(url, {waitUntil:'domcontentloaded'});
    await page.goto('https://2moody-core.ddev.site/node/' + fixture.node + '/layout', {waitUntil:'domcontentloaded'});
    await page.locator('#layout-builder').waitFor();
    const link = page.locator('[data-ai-assistant-contextual-edit]').first();
    try { await link.waitFor({state:'attached',timeout:10000}); }
    catch (error) {
      console.log(await page.evaluate(() => ({
        assistant:document.querySelectorAll('.ai-moody-assistant').length,
        scopeInput:document.querySelectorAll('[data-ai-assistant-edit-component]').length,
        editRefs:document.querySelectorAll('[data-ai-assistant-block-ref-mode="edit"]').length,
        blocks:document.querySelectorAll('.layout-builder-block[data-layout-block-uuid]').length,
        links:Array.from(document.querySelectorAll('.contextual-links a')).slice(0,8).map(a=>({text:a.textContent,href:a.getAttribute('href')})),
        scripts:Array.from(document.scripts).map(s=>s.src).filter(src=>src.includes('assistant')),
      })));
      console.log({errors});
      await page.screenshot({path:'/tmp/moody-ai-focused-edit-debug.png'});
      throw error;
    }
    const count = await page.locator('[data-ai-assistant-contextual-edit]').count();
    // Open the native contextual menu before activating the new action.
    const block = link.locator('xpath=ancestor::*[contains(concat(" ", normalize-space(@class), " "), " layout-builder-block ")][1]');
    await block.hover();
    await block.locator('.contextual .trigger').click({force:true});
    await link.click();
    const scope = await page.locator('[data-ai-assistant-edit-component]').inputValue();
    if (!scope) throw new Error('No focused block selected.');
    if (!(await page.locator('.ai-moody-assistant').getAttribute('class')).includes('is-open')) throw new Error('Assistant did not open.');
    const assistant = page.locator('.ai-moody-assistant');
    const uploads = assistant.locator('[data-ai-assistant-previous-upload-trigger]');
    await uploads.focus();
    await page.keyboard.press('Enter');
    await assistant.locator('[data-ai-assistant-tool-dialog="previous-uploads"]').waitFor({state:'visible'});
    await page.keyboard.press('Escape');
    if (await assistant.locator('[data-ai-assistant-tool-open="help"], [data-ai-assistant-tool-open="prompts"]').count()) throw new Error('Removed tools still present');
    await assistant.locator('[data-ai-assistant-prefer-ai-images-toggle]').check();
    if (await assistant.locator('[data-ai-assistant-prefer-ai-images-input]').inputValue() !== '1') throw new Error('Image preference did not update');
    await assistant.locator('[data-ai-assistant-prefer-ai-images-toggle]').uncheck();
    await assistant.locator('[data-ai-assistant-composer-editor]').fill('Make this block more concise.');
    if (!(await assistant.locator('[data-ai-assistant-composer-source]').inputValue()).includes('Make this block more concise.')) throw new Error('Prompt did not synchronize');
    const maxBytes = Number(await assistant.locator('input[type="file"]').getAttribute('data-max-file-bytes'));
    await assistant.locator('input[type="file"]').setInputFiles({name:'oversized-qa.txt',mimeType:'text/plain',buffer:Buffer.alloc(maxBytes + 1)});
    await assistant.locator('[data-ai-assistant-dropzone-hint]').waitFor({state:'visible'});
    if (!(await assistant.locator('[data-ai-assistant-dropzone-hint]').textContent()).includes('file limit')) throw new Error('Upload error is not readable');
    await assistant.locator('input[type="file"]').setInputFiles({name:'composer-qa.txt',mimeType:'text/plain',buffer:Buffer.from('Disposable composer attachment')});
    await assistant.locator('[data-ai-assistant-file-list]').waitFor({state:'visible'});
    if (await assistant.locator('[data-ai-assistant-dropzone-hint]').isVisible()) throw new Error('Upload error did not clear');
    const toolbar = assistant.locator('.ai-moody-assistant__composer-toolbar');
    if (await toolbar.locator('[data-ai-assistant-dropzone], [data-ai-assistant-previous-upload-trigger], [data-ai-assistant-prefer-ai-images-toggle], [data-ai-assistant-token-counter-toggle], input[type="submit"]').count() !== 5) throw new Error('Composer controls are not unified');
    await assistant.locator('[data-ai-assistant-token-counter-toggle]').click();
    await assistant.locator('[data-ai-assistant-token-counter-popover]').waitFor({state:'visible'});
    await assistant.locator('[data-ai-assistant-token-counter-close]').click();
    await page.screenshot({path:'/tmp/moody-ai-focused-edit-local.png'});
    for (const width of [320,375,414,768]) {
      await page.setViewportSize({width,height:1000});
      await page.screenshot({path:'/tmp/moody-ai-composer-' + width + '.png'});
      const overflow = await assistant.locator('.ai-moody-assistant__composer').evaluate(el => el.scrollWidth > el.clientWidth + 1);
      if (overflow) throw new Error('Composer overflow at ' + width);
    }
    console.log(JSON.stringify({contextualLinks:count,scopeSelected:true,menuOrder:await block.locator('.contextual-links a').allTextContents(),errors}));
  } finally {
    await browser.close();
    if (fixture) execFileSync('ddev', ['drush','php:eval', '$n=\\Drupal\\node\\Entity\\Node::load('+fixture.node+'); if($n){$s=\\Drupal::service("moody_ai_assistant.layout_context_collector")->getPreferredSectionStorage($n,["is_layout_builder_context"=>TRUE]); if($s)\\Drupal::service("layout_builder.tempstore_repository")->delete($s); $n->delete();} $b=\\Drupal\\block_content\\Entity\\BlockContent::load('+fixture.block+'); if($b)$b->delete();'], {cwd:siteDir,stdio:['ignore','pipe','pipe']});
  }
})().catch(error => {console.error(error.message.replace(/https?:\/\/\S*user\/reset\S*/g,'[redacted login URL]'));process.exitCode=1;});
