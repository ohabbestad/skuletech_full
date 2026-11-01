import assert from 'node:assert/strict';
if(process.env.PLAN_TEST_CONFIRM!=='isolated')throw Error('Set PLAN_TEST_CONFIRM=isolated.');
const base=process.env.PLAN_TEST_URL||'http://127.0.0.1:8769/plan/';
if(!/^http:\/\/127\.0\.0\.1:/.test(base))throw Error('Testen krev ein lokal, isolert tenar.');
let cookie='',csrf='';
async function call(action,extra={},auth=true){const response=await fetch(base+'api/index.php',{method:'POST',headers:{'Content-Type':'application/json',...(auth&&cookie?{Cookie:cookie}:{})},body:JSON.stringify({action,csrf:auth?csrf:'',...extra})});const c=response.headers.get('set-cookie');if(auth&&c)cookie=c.split(';')[0];return {status:response.status,data:await response.json(),headers:response.headers};}
let r=await call('session');csrf=r.data.csrf;assert.equal(r.data.authenticated,false);
assert.equal((await call('load')).status,401);
assert.equal((await call('login',{password:'test-only-password',csrf:'wrong'})).status,403);
r=await call('login',{password:'test-only-password'});assert.equal(r.status,200);csrf=r.data.csrf;
assert.match(cookie,/SKULETECH_PLAN=/);
let state=(await call('load')).data;let revision=state.revision;const doc=state.document;
assert.equal((await call('save',{revision,document:doc,csrf:'wrong'})).status,403);
doc.plans[0].title='Oppdatert æøå';r=await call('save',{revision,document:doc,label:'Integrasjon'});assert.equal(r.status,200);revision=r.data.revision;
assert.equal((await call('save',{revision:revision-1,document:doc})).status,409);
const expires='2099-01-01';
assert.equal((await call('share-create',{scope:'lesson',target:'event-a',items:['event-a','event-b'],expires})).status,422);
r=await call('share-create',{scope:'lesson',target:'event-a',items:['event-a'],expires});assert.equal(r.status,200);const {id,token}=r.data;
const pub=await call('public',{token,items:['event-b'],scope:'day',target:'2026-11-02'},false);assert.equal(pub.status,200);const encoded=JSON.stringify(pub.data);assert(!encoded.includes('PRIVATE'));assert(!encoded.includes('HEMMELEG_ANDRE_OKT'));assert.equal(pub.data.data.events.length,1);assert.match(pub.headers.get('cache-control'),/no-store/);assert.equal(pub.headers.get('referrer-policy'),'no-referrer');
assert.equal((await call('public',{token:token.slice(0,-1)+'x'},false)).status,404);
doc.plans[0].title='Siste lagra';r=await call('save',{revision,document:doc});revision=r.data.revision;assert.equal((await call('public',{token},false)).data.data.events[0].plan.title,'Siste lagra');
r=await call('share-create',{scope:'day',target:'2026-11-02',items:['event-a','event-duty'],expires});assert.equal(r.status,200);const day=r.data;
assert.equal((await call('public',{token:day.token},false)).data.data.events.length,2);
doc.events[0].date='2026-11-03';r=await call('save',{revision,document:doc});revision=r.data.revision;assert.equal((await call('public',{token:day.token},false)).data.data.events.length,1);
await call('share-update',{id,revoke:true});assert.equal((await call('public',{token},false)).status,404);
assert.equal((await call('share-create',{scope:'day',target:'2026-11-02',items:['event-b'],expires:'2020-01-01'})).status,422);
state=(await call('load')).data;const v=state.versions.at(-1);r=await call('restore',{versionId:Number(v.id),revision,label:'Test tilbakeføring'});assert.equal(r.status,200);assert.equal(r.data.document.events[0].date,'2026-11-02');
// Password changes preserve plans/shares and invalidate another device immediately.
async function device(){let deviceCookie='',deviceCsrf='';return async(action,fields={})=>{const res=await fetch(base+'api/index.php',{method:'POST',headers:{'Content-Type':'application/json',...(deviceCookie?{Cookie:deviceCookie}:{})},body:JSON.stringify({action,csrf:deviceCsrf,...fields})});const c=res.headers.get('set-cookie');if(c)deviceCookie=c.split(';')[0];const data=await res.json();if(data.csrf)deviceCsrf=data.csrf;return {status:res.status,data};};}
const other=await device();await other('session');assert.equal((await other('login',{password:'test-only-password'})).status,200);
assert.equal((await call('password-change',{currentPassword:'wrong',newPassword:'new-test-password-123',confirmPassword:'new-test-password-123'})).status,422);
assert.equal((await call('password-change',{currentPassword:'test-only-password',newPassword:'short',confirmPassword:'short'})).status,422);
assert.equal((await call('password-change',{currentPassword:'test-only-password',newPassword:'new-test-password-123',confirmPassword:'different-test-password'})).status,422);
assert.equal((await call('password-change',{currentPassword:'test-only-password',newPassword:'new-test-password-123',confirmPassword:'new-test-password-123',csrf:'wrong'})).status,403);
const beforeChange=(await call('load')).data;
r=await call('password-change',{currentPassword:'test-only-password',newPassword:'new-test-password-123',confirmPassword:'new-test-password-123'});assert.equal(r.status,200);csrf=r.data.csrf;
assert.equal((await other('session')).data.authenticated,false);assert.equal((await other('load')).status,401);
const loginCheck=await device();await loginCheck('session');assert.equal((await loginCheck('login',{password:'test-only-password'})).status,401);assert.equal((await loginCheck('login',{password:'new-test-password-123'})).status,200);
const afterChange=(await call('load')).data;assert.deepEqual(afterChange.document,beforeChange.document);assert.deepEqual(afterChange.shares,beforeChange.shares);
r=await call('password-change',{currentPassword:'new-test-password-123',newPassword:'test-only-password',confirmPassword:'test-only-password'});assert.equal(r.status,200);csrf=r.data.csrf;
await call('logout');assert.equal((await call('load')).status,403);
// Deliberate failed attempts must be throttled, including across session cookies.
cookie='';csrf=(await call('session')).data.csrf;for(let i=0;i<10;i++)assert.equal((await call('login',{password:'wrong-password'})).status,401);
assert.equal((await call('login',{password:'wrong-password'})).status,429);
console.log('OK: innlogging, CSRF, revisjonskonflikt, siste versjon, avgrensa deling, private felt, flytta økt, tilbakekalling, utløpsdato, historikk og innloggingsgrense.');
