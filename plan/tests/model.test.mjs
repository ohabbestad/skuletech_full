import {test} from 'node:test';
import assert from 'node:assert/strict';
import {empty,parseICS,mergeCalendar,mergeJSON,copyPlan,blankPlan,weekDocument,weekNo,dayFocus,osloClock,previousReflection} from '../assets/model.mjs';
const cal=(start='20261026T083000',end='20261026T100000',title='9A Matematikk')=>`BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:fixture-1\r\nDTSTART;TZID=Europe/Oslo:${start}\r\nDTEND;TZID=Europe/Oslo:${end}\r\nSUMMARY:${title}\r\nDESCRIPTION:Timeplanveke B\\nFag\r\nEND:VEVENT\r\nEND:VCALENDAR`;
test('ICS bevarer UID, klokkeslett, norske teikn og bretta linjer',()=>{const [e]=parseICS(cal(undefined,undefined,'9A Kropps\r\n øving'),'timeplan');assert.equal(e.title,'9A Kroppsøving');assert.equal(e.start,'08:30');assert.equal(e.end,'10:00');assert.equal(e.weekType,'B');assert.equal(e.uid,'fixture-1');});
test('UTC blir konvertert med norsk sommar- og vintertid',()=>{const parse=(d)=>parseICS(cal(d+'T083000Z',d+'T100000Z').replaceAll(';TZID=Europe/Oslo',''),'timeplan')[0].start;assert.equal(parse('20260601'),'10:30');assert.equal(parse('20261201'),'09:30');});
test('ICS gjentaking og duplisert UID blir avvist',()=>{assert.throws(()=>parseICS(cal().replace('SUMMARY:','RRULE:FREQ=WEEKLY\r\nSUMMARY:'),'timeplan'));assert.throws(()=>parseICS(cal().replace('END:VCALENDAR',cal()),'timeplan'));});
test('import er idempotent og bevarer planar og manuelle endringar',()=>{const events=parseICS(cal(),'timeplan');let d=mergeCalendar(empty(),events,'timeplan').document;const p=blankPlan(events[0]);p.notes='Privat';d.plans.push(p);d.events[0].start='08:40';d.events[0].override=true;let result=mergeCalendar(d,parseICS(cal('20261026T090000','20261026T103000'),'timeplan'),'timeplan');assert.equal(result.document.events.length,1);assert.equal(result.document.events[0].start,'08:40');assert.equal(result.document.plans[0].notes,'Privat');assert.equal(mergeCalendar(result.document,parseICS(cal('20261026T090000','20261026T103000'),'timeplan'),'timeplan').changes.length,0);});
test('manglande hendingar blir flagga, ikkje sletta',()=>{const d=mergeCalendar(empty(),parseICS(cal(),'timeplan'),'timeplan').document;const r=mergeCalendar(d,[],'timeplan');assert.equal(r.document.events.length,1);assert.equal(r.document.events[0].missing,true);});
test('kopiering bevarer målklasse og private målnotat utan å kopiere kjeldenotat',()=>{const source={title:'Sannsyn',notes:'Kjelde privat',reflection:'Kjelde refleksjon',activities:[]},target={id:'p',eventId:'e',group:'9B',notes:'Mål privat',reflection:'Mål refleksjon'};const result=copyPlan(source,target,{group:'9B',subject:'Matematikk'});assert.equal(result.notes,'Mål privat');assert.equal(result.group,'9B');assert.equal(result.id,'p');assert.equal(result.status,'draft');});
test('ukjend øktkopling krev val; vekeeksport held andre veker utanfor',()=>{const d=empty();d.events=parseICS(cal(),'timeplan');const p={...blankPlan(),eventId:'ukjend'};assert.equal(mergeJSON(d,{...empty(),plans:[p]}).unresolved.length,1);assert.equal(mergeJSON(d,{...empty(),plans:[p]},{[p.id]:null}).document.plans.length,1);assert.equal(weekDocument(d,'2026-11-02').events.length,0);assert.deepEqual(weekNo('2027-01-01'),{year:2026,week:53});});
test('no/neste handterer grense mellom undervisning og inspeksjon og hoppar over avlysingar',()=>{
    const events=[{id:'a',date:'2026-11-02',start:'08:30',end:'10:00'},{id:'b',date:'2026-11-02',start:'10:00',end:'10:10'},{id:'cancel',date:'2026-11-02',start:'10:10',end:'11:10',cancelled:true},{id:'c',date:'2026-11-03',start:'08:30',end:'10:00'}];
    let focus=dayFocus(events,'2026-11-02',{date:'2026-11-02',time:'09:55'});assert.equal(focus.current[0].id,'a');assert.equal(focus.next.id,'b');assert.equal(focus.untilNext,5);
    focus=dayFocus(events,'2026-11-02',{date:'2026-11-02',time:'10:00'});assert.deepEqual(focus.current.map(e=>e.id),['b']);assert.equal(focus.next.id,'c');assert.equal(focus.untilNext,null);
    assert.equal(dayFocus(events,'2026-11-02',{date:'2026-11-03',time:'11:00'}).next.id,'a');
});
test('tom dag viser neste daterte aktivitet og norsk klokke følgjer sommartid',()=>{
    assert.equal(dayFocus([],'2026-11-01',{date:'2026-11-01',time:'12:00'}).next,null);
    assert.deepEqual(osloClock(new Date('2026-06-01T07:30:00Z')),{date:'2026-06-01',time:'09:30'});
    assert.deepEqual(osloClock(new Date('2026-11-01T07:30:00Z')),{date:'2026-11-01',time:'08:30'});
});
test('etterarbeid blir henta frå rett fag og klasse og blir ikkje kopiert frå kjelde',()=>{
    const d=empty();d.events=[{id:'old',date:'2026-10-26',start:'08:30',subject:'Matematikk',group:'9A',kind:'lesson'},{id:'other',date:'2026-10-30',start:'08:30',subject:'Matematikk',group:'9B',kind:'lesson'}];d.plans=[{eventId:'old',followUp:'Øv meir på frekvens.'},{eventId:'other',followUp:'Ein annan klasse.'}];
    assert.equal(previousReflection(d,{date:'2026-11-02',start:'08:30',subject:'Matematikk',group:'9A'}).event.id,'old');
    assert.equal(previousReflection(d,{date:'2026-11-02',start:'08:30',subject:'Naturfag',group:'9A'}),null);
    assert.equal(copyPlan({followUp:'KJELDE'},{followUp:'MÅL'},{group:'9A'}).followUp,'MÅL');
});
test('dagsnotat blir bevarte ved eldre import og filtrerte i vekeeksport',()=>{
    const d=empty();d.dayNotes=[{date:'2026-11-02',note:'PRIVAT'},{date:'2026-11-09',note:'SEINARE'}];
    assert.deepEqual(weekDocument(d,'2026-11-02').dayNotes,[{date:'2026-11-02',note:'PRIVAT'}]);
    assert.deepEqual(mergeJSON(d,{schemaVersion:1,events:[],plans:[]}).document.dayNotes,d.dayNotes);
    const updated=mergeJSON(d,{...empty(),dayNotes:[{date:'2026-11-02',note:'ENDRA'}]}).document;
    assert.equal(updated.dayNotes[0].note,'ENDRA');assert.equal(updated.dayNotes[1].note,'SEINARE');
    d.plans=[{id:'p',eventId:null,title:'Før',progress:'PRIVAT ETTERARBEID'}];
    const legacy=mergeJSON(d,{schemaVersion:1,events:[],plans:[{id:'p',eventId:null,title:'Ny tittel'}]}).document;
    assert.equal(legacy.plans[0].progress,'PRIVAT ETTERARBEID');
});
