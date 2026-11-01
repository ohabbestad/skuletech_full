const {createRequire}=require('node:module');
const assert=require('node:assert/strict');
const path=require('node:path');
const fs=require('node:fs');
const requirePW=process.env.PLAYWRIGHT_MODULE?createRequire(process.env.PLAYWRIGHT_MODULE):require;
const {chromium}=requirePW('playwright');
if(process.env.PLAN_TEST_CONFIRM!=='isolated')throw Error('Set PLAN_TEST_CONFIRM=isolated.');
const url=process.env.PLAN_TEST_URL||'http://127.0.0.1:8769/plan/';
if(!url.startsWith('http://127.0.0.1:'))throw Error('Berre lokal test.');
const shots=process.env.PLAN_SCREENSHOTS||path.join(process.cwd(),'codex-temp-plan-screenshots');
(async()=>{fs.mkdirSync(shots,{recursive:true});const browser=await chromium.launch({channel:'msedge',headless:true});const ctx=await browser.newContext({viewport:{width:1440,height:1000}});const page=await ctx.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
try{
await page.goto(url);await page.locator('#password').fill('test-only-password');await page.getByRole('button',{name:/Opne planlegginga/}).click();await page.locator('#workspace').waitFor({state:'visible'});await page.locator('#week-date').fill('2026-11-02');
for(const width of [375,768,1024,1440]){await page.setViewportSize({width,height:1000});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`Overflow ${width}`);await page.screenshot({path:path.join(shots,`veke-${width}.png`),fullPage:true});}
await page.setViewportSize({width:1440,height:1000});await page.locator('[data-act="edit-event"][data-id="event-a"]').click();await page.locator('#f-title').fill('Nytt tema æøå');await page.locator('#f-notes').fill('PRIVATE_BROWSER_SENTINEL');
await page.route('**/api/index.php',route=>{if(route.request().postDataJSON()?.action==='save')route.abort();else route.continue();});await page.getByRole('button',{name:'Lagre økta',exact:true}).click();await page.waitForTimeout(250);assert(await page.locator('#editor').isVisible());assert.equal(await page.locator('#f-title').inputValue(),'Nytt tema æøå');await page.unroute('**/api/index.php');await page.getByRole('button',{name:'Lagre økta',exact:true}).click();await page.locator('#editor').waitFor({state:'hidden'});
await page.locator('[data-act="edit-event"][data-id="event-a"]').click();await page.locator('[data-editor="share"]').click();await page.getByRole('button',{name:'Opprett lenkje',exact:true}).click();await page.locator('#share-url').waitFor();const shareURL=await page.locator('#share-url').inputValue();const pub=await ctx.newPage();await pub.goto(shareURL);await pub.getByRole('heading',{name:'Nytt tema æøå'}).waitFor();assert(!(await pub.locator('body').innerText()).includes('PRIVATE_BROWSER_SENTINEL'));assert(!(await pub.locator('body').innerText()).includes('HEMMELEG_ANDRE_OKT'));await pub.emulateMedia({media:'print'});await pub.screenshot({path:path.join(shots,'deling-print.png'),fullPage:true});await pub.close();await page.locator('[data-close="utility"]').click();await page.locator('[data-close="editor"]').click();
const p2=await ctx.newPage();await p2.goto(url);await p2.locator('#workspace').waitFor({state:'visible'});await p2.locator('#week-date').fill('2026-11-02');await p2.locator('[data-act="edit-event"][data-id="event-a"]').click();await p2.locator('#f-title').fill('Utkast i andre fane');await page.locator('[data-act="edit-event"][data-id="event-a"]').click();await page.locator('#f-title').fill('Lagra frå første fane');await page.getByRole('button',{name:'Lagre økta',exact:true}).click();await page.locator('#editor').waitFor({state:'hidden'});await p2.getByRole('button',{name:'Lagre økta',exact:true}).click();await p2.getByRole('heading',{name:'Planen er endra i ei anna fane'}).waitFor();assert.equal(await p2.locator('#f-title').inputValue(),'Utkast i andre fane');
await p2.close({runBeforeUnload:false});
await page.locator('[data-view="settings"]').click();await page.getByRole('heading',{name:'Import og historikk',exact:true}).waitFor();assert.equal(errors.length,0,errors.join('\n'));
console.log('OK: nettlesar 375–1440 px, redigering, nettverksfeil, deling, private felt, utskrift og to-fane-konflikt.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1);});
