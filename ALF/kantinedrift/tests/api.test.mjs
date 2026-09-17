// Run only against the isolated fixture documented in README.md, never production.
import assert from 'node:assert/strict';
const base = process.env.KANTINE_TEST_URL;
if (!base || new URL(base).hostname !== '127.0.0.1' || process.env.KANTINE_TEST_CONFIRM !== 'isolated') throw new Error('Set KANTINE_TEST_URL to the isolated loopback server and KANTINE_TEST_CONFIRM=isolated.');
let checks = 0;
const check = (condition, message) => { assert.ok(condition, message); checks++; };
class Client {
  constructor(role) { this.role = role; this.cookie = ''; }
  async call(payload, status = 200) {
    const res = await fetch(`${base}/api/index.php`, { method: 'POST', headers: { 'Content-Type':'application/json', Cookie:this.cookie }, body:JSON.stringify({role:this.role,...payload}) });
    if (res.headers.get('set-cookie')) this.cookie = res.headers.get('set-cookie').split(';')[0];
    const data = await res.json();
    assert.equal(res.status, status, JSON.stringify(data));
    return data;
  }
  login() { return this.call({action:'load',password:'test-only'}); }
  load() { return this.call({action:'load'}); }
  async save(payload, status=200) { const state = await this.load(); return this.call({action:'save',revision:state.revision,...payload},status); }
}
const teacher = new Client('laerar'), second = new Client('laerar'), leader = new Client('driftsleiar'), staff = new Client('tilsett');
await new Client('laerar').call({action:'load'},401);
const publicData = await new Client('infoskjerm').load();
check(Object.keys(publicData).sort().join(',') === 'kalender,menus','Public response only contains screen data');
check(publicData.kalender.every(d=>Object.keys(d).sort().join(',')==='id,merknad,status,turnusType,veke'),'No attendance, staffing or checklists on public API');
let state = await teacher.login(); await second.login(); await leader.login(); await staff.login();
const today=state.today, originalTemplate=structuredClone(state.templateRows);
const dayOf = (data,date=today)=>data.kalender.find(d=>d.id===date);
check(dayOf(state).taskRows.some(t=>t.id.startsWith('legacy_') && t.done),'Legacy early_0 mapped to stable ID');
const initialTasks = structuredClone(dayOf(state).taskRows);
const futureDays = state.kalender.filter(d=>d.id>today).map(d=>d.id);
const [customDate, ordinaryDate]=futureDays;
await teacher.save({type:'day_tasks',dateId:customDate,rows:[...initialTasks,{id:'test_custom',label:'Berre denne dagen',shift:'early'}]});
const weekday = Number(new Intl.DateTimeFormat('en-US',{weekday:'short'}).format(new Date(`${ordinaryDate}T12:00:00`)) === 'Sun' ? 0 : new Date(`${ordinaryDate}T12:00:00`).getDay());
const newRows = [...originalTemplate,{id:'test_common',label:'Ny felles rutine',shift:'early',weekday:0},{id:'test_weekday',label:'Varemottak',shift:'late',weekday:Math.min(Math.max(weekday,1),5)}];
state=await teacher.save({type:'task_templates',rows:newRows});
check(!dayOf(state).taskRows.some(t=>t.id==='test_common'),'Template changes do not alter today');
check(!dayOf(state,customDate).taskRows.some(t=>t.id==='test_common'),'Customized future date preserved');
check(dayOf(state,ordinaryDate).taskRows.some(t=>t.id==='test_common'),'Uncustomized future date refreshed');
const oldPast=state.kalender.find(d=>d.id<today);
check(!oldPast.taskRows.some(t=>t.id==='test_common'),'History preserved');
state=await teacher.save({type:'day_tasks',dateId:customDate,reset:true});
check(!dayOf(state,customDate).tasksCustom && dayOf(state,customDate).taskRows.some(t=>t.id==='test_common'),'Reset uses effective date template');
state=await teacher.save({type:'day_tasks',dateId:today,rows:[...initialTasks].reverse()});
check(dayOf(state).taskRows.find(t=>t.id===initialTasks[0].id).done===initialTasks[0].done,'Reorder keeps checks');
state=await teacher.save({type:'day_staffing',dateId:today,leader:'Leiar Ø',early:[],late:['Elev Æ','Elev Å']});
check(dayOf(state).staffing.early.length===0 && dayOf(state).staffingCustom,'Explicit empty shift does not inherit');
check(dayOf(state).staffing.leader==='Leiar Ø','Unicode daily leader');
state=await teacher.save({type:'day_staffing',dateId:today,reset:true});
check(dayOf(state).staffing.early.length>0 && !dayOf(state).staffingCustom,'Staff reset restores baseline');
await leader.save({type:'day_staffing',dateId:today,reset:true},403);
await leader.save({type:'task_check',dateId:today,key:initialTasks[0].id,value:true},403);
await staff.save({type:'menu_day',dateId:today,value:'No access'},403);
await staff.save({type:'task_check',dateId:customDate,key:initialTasks[0].id,value:true},403);
await teacher.save({type:'calendar_day',dateId:today,status:'closed',note:'Planleggingsdag'});
check((await new Client('infoskjerm').load()).kalender.find(d=>d.id===today).merknad==='Planleggingsdag','TV closed-day message preserved');
await staff.save({type:'task_check',dateId:today,key:initialTasks[0].id,value:true},403);
state=await teacher.save({type:'task_check',dateId:today,key:initialTasks[0].id,value:true});
check(dayOf(state).taskRows.find(t=>t.id===initialTasks[0].id).done,'Teacher correction allowed on closed day');
state=await teacher.save({type:'calendar_day',dateId:today,status:'open',note:''});
await teacher.save({type:'task_check',dateId:today,key:'nonexistent',value:true},400);
const before=await teacher.load();
await teacher.save({type:'day_tasks',dateId:today,rows:[{id:'same',label:'A',shift:'early'},{id:'same',label:'B',shift:'late'}]},400);
check((await teacher.load()).revision===before.revision,'Failed writes roll back revision');
state=await leader.save({type:'menu_day',dateId:today,value:'Grønsakssuppe med rundstykke',allergens:['gluten'],alternative:'Suppe med glutenfritt brød',alternativeAllergens:[],alternativeFree:true});
check(state.menus[today].alternativ_allergenfri && state.menus[today].allergens[0]==='gluten','Menu/allergen round trip');
state=await leader.save({type:'attendance',dateId:today,name:'Elev A',value:false});
check(state.attendance[today]['Elev A']===false,'Absence saved');
await leader.save({type:'vikar_add',dateId:today,name:'Vikar A',shift:'tidleg'});
state=await leader.save({type:'vikar_add',dateId:today,name:'Vikar A',shift:'tidleg'});
check(dayOf(state).substitutes.tidleg.filter(n=>n==='Vikar A').length===1,'No duplicate substitutes');
const stale=await second.load();
await teacher.save({type:'attendance',dateId:today,name:'Elev B',value:false});
const conflict=await second.call({action:'save',revision:stale.revision,type:'attendance',dateId:today,name:'Elev B',value:true},409);
check(conflict.conflict && (await teacher.load()).attendance[today]['Elev B']===false,'Stale client cannot overwrite');
const monday='2034-12-25';
state=await teacher.save({type:'create_week',monday,turnus:'B'});
check(state.kalender.filter(d=>d.weekStart===monday).length===5,'Creates five days and snapshots');
check(dayOf(state,monday).taskRows.some(t=>t.id==='test_common'),'New week uses effective template');
await teacher.save({type:'create_week',monday,turnus:'C'},400);
await teacher.save({type:'create_week',monday:'2034-12-26',turnus:'C'},400);
const unrelated=state.kalender.filter(d=>d.weekStart!==monday).length;
state=await teacher.save({type:'delete_week',monday});
check(state.kalender.length===unrelated && !dayOf(state,monday),'Week deletion uses date range');
await teacher.save({type:'task_templates',rows:originalTemplate});
await teacher.save({type:'day_tasks',dateId:today,reset:true});
await teacher.save({type:'attendance',dateId:today,name:'Elev B',value:true});
await staff.call({action:'logout'}); await staff.load().then(()=>{throw new Error('Logout failed');},e=>assert.match(e.message,/401/));
console.log(`${checks} API integration checks passed; role restrictions, migration, snapshots, conflict and atomicity exercised.`);
