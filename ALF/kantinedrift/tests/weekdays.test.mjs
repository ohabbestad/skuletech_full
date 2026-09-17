import assert from 'node:assert/strict';
const base = process.env.KANTINE_TEST_URL;
if (!base || new URL(base).hostname !== '127.0.0.1' || process.env.KANTINE_TEST_CONFIRM !== 'isolated') throw new Error('Use the isolated local fixture.');
let cookie = '', checks = 0;
async function call(payload, status = 200) {
  const res = await fetch(`${base}/api/index.php`, {method:'POST', headers:{'Content-Type':'application/json', Cookie:cookie}, body:JSON.stringify({role:'laerar',...payload})});
  if (res.headers.get('set-cookie')) cookie = res.headers.get('set-cookie').split(';')[0];
  const data = await res.json();
  assert.equal(res.status,status,JSON.stringify(data));
  return data;
}
const load = () => call({action:'load'});
async function save(payload, status = 200) { return call({action:'save',revision:(await load()).revision,...payload},status); }
const check = (value,message) => { assert.ok(value,message); checks++; };
const addDays = (date,n) => { const d = new Date(`${date}T12:00:00Z`); d.setUTCDate(d.getUTCDate()+n); return d.toISOString().slice(0,10); };
let state = await call({action:'load',password:'test-only'});
const original = structuredClone(state.templateRows);
const history = JSON.stringify(state.kalender.filter(d=>d.id<=state.today).map(d=>[d.id,d.taskRows]));
let monday = addDays(state.today,35-((new Date(`${state.today}T12:00:00Z`).getUTCDay()+6)%7));
while(state.kalender.some(d=>d.id>=monday&&d.id<=addDays(monday,11))) monday=addDays(monday,14);
const created = [], id = `multi_${Date.now()}`;
const day = (s,date) => s.kalender.find(d=>d.id===date);
const has = (s,date) => day(s,date).taskRows.some(t=>t.id===id);
try {
  state = await save({type:'create_week',monday,turnus:'A'}); created.push(monday);
  const custom = addDays(monday,2);
  await save({type:'day_tasks',dateId:custom,rows:[{id:'custom_weekdays',label:'Eiga dagsoppgåve',shift:'early'}]});
  const rows = [...original,{id,label:'Måndag, onsdag og fredag',shift:'late',weekdays:[1,3,5]}];
  state = await save({type:'task_templates',rows});
  assert.deepEqual(state.templateRows.find(t=>t.id===id).weekdays,[1,3,5]); checks++;
  check(has(state,monday)&&!has(state,addDays(monday,1))&&!has(state,addDays(monday,3))&&has(state,addDays(monday,4)),'Only chosen days receive task');
  check(!has(state,custom)&&day(state,custom).tasksCustom,'Custom day preserved');
  check(JSON.stringify(state.kalender.filter(d=>d.id<=state.today).map(d=>[d.id,d.taskRows]))===history,'Today and history preserved');
  await save({type:'task_check',dateId:monday,key:id,value:true});
  state = await save({type:'task_templates',rows:[...rows].reverse()});
  check(day(state,monday).taskRows.find(t=>t.id===id).done,'Reordering preserves completion');
  state = await save({type:'day_tasks',dateId:custom,reset:true});
  check(has(state,custom)&&!day(state,custom).tasksCustom,'Reset uses multiple weekdays');
  const nextMonday=addDays(monday,7);
  state = await save({type:'create_week',monday:nextMonday,turnus:'B'}); created.push(nextMonday);
  for(let i=0;i<5;i++) check(has(state,addDays(nextMonday,i))===[0,2,4].includes(i),'New week uses selected days');
  const revision=state.revision;
  await save({type:'task_templates',rows:[{id,label:'Tomt dagval',shift:'early',weekdays:[]}]},400);
  check((await load()).revision===revision,'Empty selection rejected atomically');
  console.log(`${checks} multiple-weekday integration checks passed.`);
} finally {
  await save({type:'task_templates',rows:original});
  for(const week of created) await save({type:'delete_week',monday:week});
}
