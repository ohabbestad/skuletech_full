const {createRequire}=require('node:module');
const assert=require('node:assert/strict');
const path=require('node:path');
const fs=require('node:fs');
const {chromium}=(process.env.PLAYWRIGHT_MODULE?createRequire(process.env.PLAYWRIGHT_MODULE):require)('playwright');
if(process.env.PLAN_TEST_CONFIRM!=='isolated')throw Error('Set PLAN_TEST_CONFIRM=isolated.');
const url=process.env.PLAN_TEST_URL||'http://127.0.0.1:8769/plan/';
if(!url.startsWith('http://127.0.0.1:'))throw Error('Berre isolert lokal test.');
const shots=process.env.PLAN_SCREENSHOTS||path.join(process.cwd(),'codex-temp-plan-screenshots');
(async()=>{
    fs.mkdirSync(shots,{recursive:true});
    const browser=await chromium.launch({channel:'msedge',headless:true});
    const ctx=await browser.newContext({viewport:{width:1440,height:1050}});
    const page=await ctx.newPage(),errors=[];
    page.on('pageerror',e=>errors.push(e.message));
    const login=async(p,password='test-only-password')=>{await p.goto(url);await p.locator('#password').fill(password);await p.getByRole('button',{name:/Opne planlegginga/}).click();await p.locator('#workspace').waitFor({state:'visible'});};
    try {
        await login(page);
        await page.getByRole('heading',{name:'Dagen i dag',exact:true}).waitFor();
        await page.locator('#day-date').fill('2026-11-02');
        assert.equal(await page.locator('.day-card').count(),3);
        for(const width of [375,768,1440]) {
            await page.setViewportSize({width,height:1050});
            assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`Overflow ${width}`);
            await page.screenshot({path:path.join(shots,`dagen-${width}.png`),fullPage:true});
        }
        const first=page.locator('.day-card.lesson').first();
        await first.getByRole('button',{name:'Start økta',exact:true}).click();
        assert(!(await page.locator('#utility-content').innerText()).includes('PRIVATE_SECRET'));
        await page.getByRole('button',{name:'Avslutt og noter'}).click();
        await page.locator('#f-after-progress').fill('PRIVATE_PROGRESS: Vi kom til oppgåve 4.');
        await page.locator('#f-after-followUp').fill('PRIVATE_FOLLOWUP: Repeter frekvens neste gong.');
        await page.locator('#f-after-adjustment').fill('PRIVATE_ADJUSTMENT: Bruk fleire døme.');
        await page.getByRole('button',{name:'Lagre og merk gjennomført'}).click();
        await page.locator('#utility').waitFor({state:'hidden'});
        assert.match(await page.locator('.day-card.lesson').first().innerText(),/Gjennomført/);
        await page.locator('#day-note').fill('PRIVATE_DAY_NOTE: Ta med myntar.');
        await page.route('**/api/index.php',route=>route.request().postDataJSON()?.action==='save'?route.abort():route.continue());
        await page.locator('#save').click();await page.waitForTimeout(250);
        assert.equal(await page.locator('#day-note').inputValue(),'PRIVATE_DAY_NOTE: Ta med myntar.');
        await page.unroute('**/api/index.php');await page.locator('#save').click();await page.locator('#save:disabled').waitFor();
        await page.locator('#day-note').fill('PRIVATE_DAY_NOTE: Lagre første utkast.');
        await page.route('**/api/index.php',async route=>{if(route.request().postDataJSON()?.action==='save')await new Promise(resolve=>setTimeout(resolve,700));await route.continue();});
        await page.locator('#save').click();
        await page.locator('#day-note').fill('PRIVATE_DAY_NOTE: Skreiv vidare medan lagring gjekk.');
        await page.getByText('Ulagra endringar',{exact:true}).waitFor();
        assert.equal(await page.locator('#day-note').inputValue(),'PRIVATE_DAY_NOTE: Skreiv vidare medan lagring gjekk.');
        await page.unroute('**/api/index.php');await page.locator('#save').click();await page.locator('#save:disabled').waitFor();
        await page.getByRole('button',{name:'+ Gjeremål',exact:true}).click();
        assert.equal(await page.locator('#f-todoDate').inputValue(),'2026-11-02');
        await page.locator('#f-todoText').fill('Gjer klart materiell');await page.getByRole('button',{name:'Legg til',exact:true}).click();await page.locator('#utility').waitFor({state:'hidden'});
        await page.locator('[data-todo]').first().check();await page.locator('#save').click();await page.locator('#save:disabled').waitFor();
        await page.reload();await page.locator('#day-date').fill('2026-11-02');
        assert.equal(await page.locator('#day-note').inputValue(),'PRIVATE_DAY_NOTE: Skreiv vidare medan lagring gjekk.');
        assert(await page.locator('[data-todo]').first().isChecked());
        // The next lesson in the same class/subject picks up private follow-up.
        await page.locator('[data-view="settings"]').click();
        await page.locator('#json-file').setInputFiles({name:'fixture.json',mimeType:'application/json',buffer:Buffer.from(JSON.stringify({schemaVersion:1,events:[{id:'next-maths',kind:'lesson',date:'2026-11-09',start:'08:30',end:'10:00',allDay:false,title:'9A Matematikk',subject:'Matematikk',group:'9A'}],plans:[]}))});
        await page.getByRole('button',{name:'Godkjenn og lagre'}).click();await page.locator('#utility').waitFor({state:'hidden'});
        await page.locator('[data-view="today"]').click();await page.locator('#day-date').fill('2026-11-09');
        await page.getByText(/Privat oppfølging frå førre økt/).click();
        assert.match(await page.locator('.prior-reflection').innerText(),/PRIVATE_FOLLOWUP/);
        await page.locator('#day-date').fill('2026-11-02');await page.locator('.day-card.lesson').first().getByRole('button',{name:'↗ Del',exact:true}).click();
        await page.getByRole('button',{name:'Opprett lenkje',exact:true}).click();const sharedURL=await page.locator('#share-url').inputValue();
        const publicPage=await browser.newPage();await publicPage.goto(sharedURL);await publicPage.getByRole('heading',{name:/Sannsyn|Matematikk/}).first().waitFor();
        assert(!(await publicPage.locator('body').innerText()).includes('PRIVATE_'));
        await publicPage.close();await page.locator('[data-close="utility"]').click();
        // An actual second device must lose its authenticated session after change.
        const otherCtx=await browser.newContext(),other=await otherCtx.newPage();await login(other);
        await page.locator('[data-view="settings"]').click();
        await page.locator('#current-password').fill('wrong');await page.locator('#new-password').fill('new-browser-password-123');await page.locator('#confirm-password').fill('new-browser-password-123');
        await page.getByRole('button',{name:'Byt passord',exact:true}).click();await page.getByText('Noverande passord er feil.',{exact:true}).waitFor();
        await page.locator('#current-password').fill('test-only-password');await page.getByRole('button',{name:'Byt passord',exact:true}).click();await page.getByText('Passordet er endra. Andre einingar må logge inn på nytt.',{exact:true}).waitFor();
        assert.equal(await page.locator('#new-password').inputValue(),'');
        await other.reload();await other.locator('#login').waitFor({state:'visible'});
        // Restore the fixture password for subsequent test runs.
        await page.locator('#current-password').fill('new-browser-password-123');await page.locator('#new-password').fill('test-only-password');await page.locator('#confirm-password').fill('test-only-password');await page.getByRole('button',{name:'Byt passord',exact:true}).click();await page.getByText('Passordet er endra. Andre einingar må logge inn på nytt.',{exact:true}).waitFor();
        await otherCtx.close();
        await page.screenshot({path:path.join(shots,'innstillingar.png'),fullPage:true});
        assert.deepEqual(errors,[]);
        console.log('OK: dagsoversikt, lesing, etterarbeid, privat oppfølging, dagsnotat, gjeremål, nettverksfeil, deling, mobil og passordbyte med utlogging av andre einingar.');
    } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
