import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mkdir } from 'node:fs/promises';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.KANTINE_TEST_URL;
if (!base || new URL(base).hostname !== '127.0.0.1' || process.env.KANTINE_TEST_CONFIRM !== 'isolated') throw new Error('Use the isolated local fixture.');
const output = process.env.KANTINE_SCREENSHOTS || 'codex-temp-kantine/screenshots';
await mkdir(output,{recursive:true});
const browser = await chromium.launch({headless:true,channel:process.env.KANTINE_BROWSER || 'msedge'});
const failures=[]; let checks=0;
const widths=[320,375,768,1024,1440];
const contexts=[];
async function login(role) {
  const context=await browser.newContext({viewport:{width:1440,height:1050}}); contexts.push(context);
  const page=await context.newPage();
  page.on('pageerror',e=>failures.push(e.message));
  await page.goto(`${base}/${role}.html`);
  await page.getByLabel('Passord',{exact:true}).fill('test-only');
  await page.getByRole('button',{name:`Logg inn som ${role==='laerar'?'lærar':role}`}).click();
  await page.getByRole('button',{name:'Logg ut',exact:true}).waitFor();
  return page;
}
async function fits(page,label) {
  const metrics=await page.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,modal:document.querySelector('dialog').open?{client:document.querySelector('dialog').clientWidth,scroll:document.querySelector('dialog').scrollWidth}:null}));
  assert.ok(metrics.scroll<=metrics.width+1,`${label}: page overflow ${JSON.stringify(metrics)}`);
  if(metrics.modal) assert.ok(metrics.modal.scroll<=metrics.modal.client+1,`${label}: dialog overflow`);
  checks++;
}
try {
  for(const role of ['laerar','driftsleiar','tilsett']) {
    const page=await login(role);
    for(const width of widths) {
      await page.setViewportSize({width,height:1050});
      await fits(page,`${role} ${width}`);
      await page.evaluate(()=>{ document.activeElement?.blur(); window.scrollTo(0,0); });
      await page.screenshot({path:`${output}/${role}-${width}.png`,fullPage:true});
      if(role!=='tilsett') {
        await page.getByRole('button',{name:role==='laerar'?'Planlegging':'Planoversikt'}).click();
        await fits(page,`plan ${role} ${width}`);
        await page.getByRole('button',{name:'Dagens drift'}).click();
        await page.getByRole('button',{name:'Endre meny',exact:true}).click();
        await fits(page,`menu ${role} ${width}`);
        await page.getByRole('button',{name:'Avbryt',exact:true}).click();
      }
    }
    await page.setViewportSize({width:768,height:550});
    // 1536px display at 200% browser zoom has a 768 CSS-pixel viewport.
    await fits(page,`${role} 200% equivalent reflow`);
    if(role==='tilsett') {
      const headings=await page.locator('main h2').allTextContents();
      assert.equal(headings[0],'Sjekklister'); checks++;
    }
    await page.close();
  }
  const page=await login('laerar');
  await page.getByRole('button',{name:'Endre meny',exact:true}).click();
  await page.getByLabel('Hovudrett',{exact:true}).fill('Utkast med æ, ø og å');
  await page.getByRole('heading',{name:'Endre dagens meny'}).click();
  await page.evaluate(()=>document.dispatchEvent(new Event('visibilitychange')));
  assert.equal(await page.getByLabel('Hovudrett',{exact:true}).inputValue(),'Utkast med æ, ø og å'); checks++;
  await page.getByRole('button',{name:'Lagre',exact:true}).click();
  await page.locator('dialog').waitFor({state:'hidden'});
  await page.getByText('Utkast med æ, ø og å',{exact:true}).waitFor(); checks++;
  // Expired session: log in again and recover the exact draft.
  await page.getByRole('button',{name:'Endre meny',exact:true}).click();
  await page.getByRole('textbox',{name:'Hovudrett',exact:true}).fill('Utkast etter ny innlogging');
  await page.request.post(`${base}/api/index.php`,{data:{role:'laerar',action:'logout'}});
  await page.getByRole('button',{name:'Lagre',exact:true}).click();
  await page.getByLabel('Passord',{exact:true}).fill('test-only');
  await page.getByRole('button',{name:'Logg inn som lærar',exact:true}).click();
  await page.getByRole('textbox',{name:'Hovudrett',exact:true}).waitFor();
  assert.equal(await page.getByRole('textbox',{name:'Hovudrett',exact:true}).inputValue(),'Utkast etter ny innlogging'); checks++;
  await page.getByRole('button',{name:'Lagre',exact:true}).click();
  await page.locator('dialog').waitFor({state:'hidden'});
  await page.getByText('Utkast etter ny innlogging',{exact:true}).waitFor(); checks++;
  // Stable identities survive editing and reordering through the real form.
  await page.getByRole('button',{name:'Tilpass dagen',exact:true}).click();
  await page.getByRole('button',{name:'+ Legg til oppgåve',exact:true}).click();
  await page.locator('[data-col="label"]').last().fill('Ekstra dagsoppgåve');
  await page.locator('[data-action="move-task"][data-step="-1"]').last().click();
  await page.getByRole('button',{name:'Lagre',exact:true}).click();
  await page.locator('dialog').waitFor({state:'hidden'});
  await page.getByText('Ekstra dagsoppgåve',{exact:true}).waitFor(); checks++;
  // Edit conflict: keep the draft and require an explicit comparison.
  const second=await login('laerar');
  await page.getByRole('button',{name:'Endre meny',exact:true}).click();
  await page.getByLabel('Hovudrett',{exact:true}).fill('Mitt bevarte utkast');
  await second.getByRole('button',{name:'Endre meny',exact:true}).click();
  await second.getByLabel('Hovudrett',{exact:true}).fill('Endring frå ein annan lærar');
  await second.getByRole('button',{name:'Lagre',exact:true}).click();
  await second.locator('dialog').waitFor({state:'hidden'});
  await page.getByRole('button',{name:'Lagre',exact:true}).click();
  await page.getByRole('button',{name:'Hent siste versjon for samanlikning',exact:true}).waitFor();
  assert.equal(await page.getByLabel('Hovudrett',{exact:true}).inputValue(),'Mitt bevarte utkast'); checks++;
  await page.getByRole('button',{name:'Hent siste versjon for samanlikning',exact:true}).click();
  await page.locator('.comparison').filter({hasText:'Endring frå ein annan lærar'}).waitFor();
  await page.getByRole('button',{name:'Lagre etter samanlikning',exact:true}).click();
  await page.locator('dialog').waitFor({state:'hidden'}); checks++;
  // A lost connection must leave fields intact, including after blur.
  await page.getByRole('button',{name:'Endre meny',exact:true}).click();
  await page.getByLabel('Hovudrett',{exact:true}).fill('Bevart ved nettverksbrot');
  await page.route('**/api/index.php',route=>route.abort('failed'));
  await page.getByRole('button',{name:'Lagre',exact:true}).click();
  await page.locator('#editor-error').filter({hasText:/./}).waitFor();
  assert.equal(await page.getByLabel('Hovudrett',{exact:true}).inputValue(),'Bevart ved nettverksbrot'); checks++;
  await page.unroute('**/api/index.php');
  await page.getByRole('button',{name:'Lagre',exact:true}).click();
  await page.locator('dialog').waitFor({state:'hidden'});
  // Setup form, task controls and keyboard focus on the narrowest display.
  await page.setViewportSize({width:320,height:700});
  await page.getByRole('button',{name:'Oppsett'}).click();
  await page.getByRole('button',{name:/Felles rutinar og vekedagsoppgåver/}).click();
  await fits(page,'weekday editor 320');
  await page.keyboard.press('Tab');
  assert.ok(await page.evaluate(()=>document.querySelector('dialog').contains(document.activeElement))); checks++;
  await page.screenshot({path:`${output}/oppgaver-320.png`,fullPage:true});
  await page.getByRole('button',{name:'Avbryt',exact:true}).click();
  await page.getByRole('button',{name:/Turnus og faste driftsleiarar/}).click();
  await fits(page,'staff template 320');
  await page.getByRole('button',{name:'Avbryt',exact:true}).click();
  await page.getByRole('button',{name:'Dagens drift'}).click();
  await page.getByRole('button',{name:'Endre bemanning',exact:true}).click();
  await page.getByLabel('Tidlegvakt',{exact:true}).fill('');
  await page.getByRole('button',{name:'Lagre',exact:true}).click();
  await page.locator('dialog').waitFor({state:'hidden'});
  await page.getByText('Ingen fast bemanning.',{exact:true}).waitFor(); checks++;
  await page.getByRole('button',{name:'Bruk grunnturnus igjen',exact:true}).click();
  await page.getByRole('button',{name:'Stadfest',exact:true}).click();
  await page.locator('dialog').waitFor({state:'hidden'});
  // Missing date is explicit, not silently replaced by another date.
  await page.getByLabel('Arbeidsdag',{exact:true}).fill('2039-01-01');
  await page.getByRole('heading',{name:'Ingen plan for denne dagen'}).waitFor(); checks++;
  await page.getByRole('button',{name:'I dag',exact:true}).click();
  // UI week creation and deletion preserve a different year's calendar.
  await page.getByRole('button',{name:'Planlegging',exact:true}).click();
  await page.getByRole('button',{name:'+ Opprett veke',exact:true}).click();
  await page.getByLabel('Dato for måndag',{exact:true}).fill('2036-01-07');
  await page.getByRole('button',{name:'Lagre',exact:true}).click();
  await page.locator('dialog').waitFor({state:'hidden'});
  await page.locator('[data-action="delete-week"][data-monday="2036-01-07"]').waitFor(); checks++;
  await page.locator('[data-action="delete-week"][data-monday="2036-01-07"]').click();
  await page.getByRole('button',{name:'Stadfest',exact:true}).click();
  await page.locator('dialog').waitFor({state:'hidden'});
  assert.equal(await page.locator('[data-action="delete-week"][data-monday="2036-01-07"]').count(),0); checks++;
  // Restore the demonstration day after the destructive-scenario tests.
  for (const payload of [{type:'day_tasks',reset:true},{type:'menu_day',value:'Grønsakssuppe med rundstykke',allergens:['gluten'],alternative:'Suppe med glutenfritt brød',alternativeAllergens:[],alternativeFree:true}]) {
    const current=await (await page.request.post(`${base}/api/index.php`,{data:{role:'laerar',action:'load'}})).json();
    const restored=await page.request.post(`${base}/api/index.php`,{data:{role:'laerar',action:'save',dateId:current.today,revision:current.revision,...payload}});
    assert.ok(restored.ok());
  }
  // Both existing public clients still render against the reduced payload.
  for(const file of ['infoskjerm.html','infoskjerm_BUS.html']) {
    const screen=await browser.newPage(); screen.on('pageerror',e=>failures.push(`${file}: ${e.message}`));
    await screen.goto(`${base}/${file}`); await screen.waitForTimeout(2000); await screen.close();
  }
  assert.deepEqual(failures,[],'Browser runtime errors');
  console.log(`${checks} browser checks passed; ${widths.length} widths × three roles, dialogs, conflicts, offline drafts and TV clients.`);
} finally { await browser.close(); }
