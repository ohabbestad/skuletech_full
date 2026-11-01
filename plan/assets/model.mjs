export const statuses = {unplanned:'Ikkje planlagt',draft:'Utkast',ready:'Klar',done:'Gjennomført'};
export const kinds = {lesson:'Undervisning',duty:'Inspeksjon',meeting:'Møte',day:'Planleggingsdag',message:'Praktisk beskjed'};
export const uid = () => crypto.randomUUID();
export const clone = value => structuredClone(value);
export const empty = () => ({schemaVersion:1,events:[],plans:[],templates:[],todos:[]});
export function dateAdd(date,days) { const d=new Date(`${date}T12:00:00Z`); d.setUTCDate(d.getUTCDate()+days); return d.toISOString().slice(0,10); }
export function monday(date) { const d=new Date(`${date}T12:00:00Z`); return dateAdd(date,-((d.getUTCDay()+6)%7)); }
export function today() { return new Intl.DateTimeFormat('sv-SE',{timeZone:'Europe/Oslo',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date()); }
export function weekNo(date) { const d=new Date(`${monday(date)}T12:00:00Z`); d.setUTCDate(d.getUTCDate()+3); const year=d.getUTCFullYear(); return {year,week:Math.ceil((((d-new Date(`${year}-01-01T12:00:00Z`))/86400000)+1)/7)}; }
export function content(p={}) { return {title:p.title||'',subject:p.subject||'',group:p.group||'',goals:p.goals||'',equipment:p.equipment||'',preparation:p.preparation||'',activities:clone(p.activities||[]),differentiation:p.differentiation||'',assessment:p.assessment||'',resources:clone(p.resources||[])}; }
export function blankPlan(event=null) { return {...content(),id:uid(),eventId:event?.id||null,subject:event?.subject||'',group:event?.group||'',status:'draft',notes:'',reflection:''}; }
export function copyPlan(from,to,event) { return {...to,...content(from),group:event?.group||to.group||'',subject:event?.subject||to.subject||'',status:'draft',notes:to.notes||'',reflection:to.reflection||''}; }
export function duration(e) { if(!e || e.allDay) return 0; const t=s=>Number(s.slice(0,2))*60+Number(s.slice(3,5)); return t(e.end)-t(e.start); }
export function weekDocument(doc,start) { const end=dateAdd(start,7),events=doc.events.filter(e=>e.date>=start&&e.date<end), ids=new Set(events.map(e=>e.id)); return {schemaVersion:1,meta:{weekStart:start,kind:'week'},events,plans:doc.plans.filter(p=>ids.has(p.eventId)),templates:[],todos:doc.todos.filter(t=>t.date>=start&&t.date<end)}; }
const unescapeICS = s => s.replace(/\\[nN]/g,'\n').replace(/\\([,;\\])/g,'$1');
function icsDate(value,params) {
    if(/^\d{8}$/.test(value)) return {date:`${value.slice(0,4)}-${value.slice(4,6)}-${value.slice(6,8)}`,time:'',allDay:true};
    const m=/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z?)$/.exec(value);
    if(!m) throw Error('Kalenderen har eit datoformat som ikkje er støtta.');
    const raw=`${m[1]}-${m[2]}-${m[3]}T${m[4]}:${m[5]}:${m[6]}`;
    if(m[7]) { const parts=new Intl.DateTimeFormat('sv-SE',{timeZone:'Europe/Oslo',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).format(new Date(raw+'Z')).split(' '); return {date:parts[0],time:parts[1],allDay:false}; }
    if(params && /TZID=/.test(params) && !/TZID="?Europe\/Oslo"?(?:;|$)/.test(params)) throw Error('Berre Europe/Oslo og UTC er støtta.');
    return {date:raw.slice(0,10),time:raw.slice(11,16),allDay:false};
}
export function parseICS(text,source) {
    if(!['timeplan','inspeksjon'].includes(source)) throw Error('Vel kalenderkjelde.');
    if(!text.includes('BEGIN:VCALENDAR')) throw Error('Dette er ikkje ei ICS-kalenderfil.');
    const blocks=[...text.replace(/\r?\n[ \t]/g,'').matchAll(/BEGIN:VEVENT\r?\n([\s\S]*?)END:VEVENT/g)];
    const seen=new Set();
    return blocks.map(block=>{
        const fields={}; for(const line of block[1].split(/\r?\n/)) { const pos=line.indexOf(':'); if(pos<0) continue; const [key,...params]=line.slice(0,pos).split(';'); fields[key]={value:line.slice(pos+1),params:params.join(';')}; }
        if(fields.RRULE || fields['RECURRENCE-ID']) throw Error('Denne importen krev konkrete kalenderdatoar. Eksporter gjentakingar som separate hendingar.');
        const rawUid=fields.UID?.value;
        if(!rawUid || seen.has(rawUid)) throw Error('Kalenderen manglar unike UID-ar.'); seen.add(rawUid);
        if(!fields.DTSTART || !fields.DTEND) throw Error('Ei kalenderhending manglar start eller slutt.');
        const start=icsDate(fields.DTSTART.value,fields.DTSTART.params),end=icsDate(fields.DTEND.value,fields.DTEND.params);
        if(!start.allDay && start.date!==end.date) throw Error('Økter over fleire datoar må delast før import.');
        if(start.allDay && end.date!==dateAdd(start.date,1)) throw Error('Heildagshendingar over fleire dagar må delast før import.');
        const title=unescapeICS(fields.SUMMARY?.value||'Utan tittel'),description=unescapeICS(fields.DESCRIPTION?.value||'');
        const group=/\b(\d{1,2}[A-Z])\b/.exec(title)?.[1]||(/^ALF\s?\d+/.test(title)?title.replace(/\s/g,''):'');
        const subject=title.startsWith('ALF')?'Arbeidslivsfag':title==='Valfag'?'Teknologi og design':title.replace(/^\d{1,2}[A-Z]\s+/,'');
        const data={id:`ics:${source}:${rawUid}`,source,uid:rawUid,title,subject:source==='inspeksjon'?'':subject,group:source==='inspeksjon'?'':group,date:start.date,start:start.time,end:end.time,allDay:start.allDay,kind:source==='inspeksjon'?'duty':'lesson',weekType:/Timeplanveke ([ABC])/.exec(description)?.[1]||'',cancelled:fields.STATUS?.value==='CANCELLED',cancelReason:'',missing:false,override:false};
        data.calendarStamp=JSON.stringify([data.title,data.date,data.start,data.end,data.allDay,data.cancelled,data.weekType]); return data;
    });
}
export function mergeCalendar(doc,incoming,source,applyCalendar=false) {
    const next=clone(doc), changes=[]; const incomingIds=new Set(incoming.map(e=>e.id));
    for(const event of incoming) {
        const index=next.events.findIndex(e=>e.id===event.id);
        if(index<0) {next.events.push(event);changes.push({type:'Ny',title:event.title,date:event.date});continue;}
        const old=next.events[index];
        if(old.calendarStamp!==event.calendarStamp || old.missing || (old.override&&applyCalendar)) {
            const preserve=old.override&&!applyCalendar;
            next.events[index]=preserve?{...old,calendarStamp:event.calendarStamp,missing:false}:{...old,...event};
            changes.push({type:preserve?'Beheld manuell endring':'Endra',title:event.title,date:event.date,detail:`${old.date} ${old.start}–${old.end} → ${event.date} ${event.start}–${event.end}`});
        }
    }
    for(const event of next.events) if(event.source===source && !incomingIds.has(event.id) && !event.missing) {event.missing=true;changes.push({type:'Manglar – sjå over',title:event.title,date:event.date});}
    return {document:next,changes};
}
export function mergeJSON(doc,input,mapping={}) {
    if(input?.schemaVersion!==1 || !Array.isArray(input.events) || !Array.isArray(input.plans)) throw Error('Forventa SkuleTech Plan JSON, schemaVersion 1, med events og plans.');
    const next=clone(doc), changes=[], unresolved=[];
    const merge=(key,rows)=>{ if(!Array.isArray(rows)) throw Error(`Ugyldig liste: ${key}`); const seen=new Set(); for(const row of rows) {if(!row || typeof row.id!=='string'||!row.id||seen.has(row.id)) throw Error('Manglande eller duplisert ID i importen.');seen.add(row.id);const i=next[key].findIndex(v=>v.id===row.id);if(i<0) next[key].push(clone(row));else next[key][i]=clone(row);changes.push({type:i<0?'Ny':'Oppdatering',title:row.title||row.label||row.text||row.id,date:row.date||''});} };
    merge('events',input.events);
    const plans=clone(input.plans);
    for(const plan of plans) {
        if(Object.hasOwn(mapping,plan.id)) plan.eventId=mapping[plan.id]||null;
        if(plan.eventId!==null && !next.events.some(e=>e.id===plan.eventId)) {unresolved.push(plan);continue;}
        const existing=next.plans.find(p=>p.eventId && p.eventId===plan.eventId && p.id!==plan.id);
        if(existing) plan.id=existing.id;
    }
    if(!unresolved.length) {merge('plans',plans);merge('templates',input.templates||[]);merge('todos',input.todos||[]);}
    return {document:next,changes,unresolved};
}
