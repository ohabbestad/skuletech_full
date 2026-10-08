// A separate browser-only demonstration. No production transport or credentials.
import { WEEKDAYS, names, canCheck, ALLERGENS } from './model.js';

export const STORAGE_KEY = 'skuletech.valencia.canteen.demo.v1';
export const DEMO_TODAY = '2026-10-21';
export const DEMO_TIME = '2026-10-21T11:30:00+02:00';
const TOMORROW = '2026-10-22';
const clone = value => JSON.parse(JSON.stringify(value));
const put = (map, key, value) => Object.defineProperty(map, key, { value, enumerable: true, writable: true, configurable: true });
const fail = (message, status = 400) => { const error = new Error(message); error.status = status; throw error; };
const text = (value, max = 255) => {
  if (typeof value !== 'string' || value.trim().length > max) fail(`Enter text of up to ${max} characters.`);
  return value.trim();
};
const date = value => {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value) || !Number.isFinite(new Date(value).getTime()) || new Date(value).toISOString().slice(0, 10) !== value) fail('Choose a valid date.');
  return value;
};
const addDays = (value, count) => new Date(new Date(`${value}T12:00:00Z`).getTime() + count * 86400000).toISOString().slice(0, 10);
const weekday = value => new Date(`${value}T12:00:00Z`).getUTCDay();
const weekNumber = value => {
  const day = new Date(`${value}T12:00:00Z`);
  day.setUTCDate(day.getUTCDate() + 4 - (day.getUTCDay() || 7));
  return Math.ceil((((day - new Date(Date.UTC(day.getUTCFullYear(), 0, 1))) / 86400000) + 1) / 7);
};
const staffNames = value => {
  if (!Array.isArray(value) || value.some(v => typeof v !== 'string')) fail('Enter a list of names.');
  return names(value.map(v => text(v, 160)).join(','));
};
function taskRows(value, template = false) {
  if (!Array.isArray(value) || value.length > 100) fail('Use at most 100 tasks.');
  const ids = new Set();
  return value.map(row => {
    const id = text(row.id, 100), label = text(row.label);
    if (!id || !label || ids.has(id) || !['early', 'late'].includes(row.shift)) fail('Each task needs a unique ID, text and shift.');
    ids.add(id);
    const result = { id, label, shift: row.shift };
    if (template) {
      const days = row.weekdays || (row.weekday ? [row.weekday] : [1, 2, 3, 4, 5]);
      if (!Array.isArray(days) || !days.length || days.some(d => !Number.isInteger(d) || d < 1 || d > 5)) fail('Choose at least one weekday for each task.');
      result.weekdays = [...new Set(days)].sort();
    }
    return result;
  });
}
const templateFor = (state, value) => [...state.templates].sort((a, b) => b.effective.localeCompare(a.effective)).find(t => t.effective <= value).rows;
const tasksFor = (state, value) => templateFor(state, value).filter(row => row.weekdays.includes(weekday(value))).map(({ weekdays, ...row }) => clone(row));
function baseStaff(state, day) {
  const key = WEEKDAYS[weekday(day.id) - 1];
  const rota = state.bemanning.turnus[day.turnusType]?.[key];
  return { leader: state.bemanning.driftsleiarar[key] || '', early: names(rota?.tidleg || ''), late: names(rota?.seint || '') };
}
function ensureDay(state, id) {
  if (!Object.hasOwn(state.dayStates, id)) put(state.dayStates, id, { staffingOverride: null, tasks: tasksFor(state, id), tasksCustom: false, checks: {}, substitutes: { tidleg: [], seint: [] } });
}
function createWeek(state, monday, turnus) {
  for (let i = 0; i < 5; i++) {
    const id = addDays(monday, i);
    state.calendar.push({ id, weekStart: monday, veke: weekNumber(id), turnusType: turnus, status: turnus === 'Holiday' ? 'closed' : 'open', merknad: '' });
    ensureDay(state, id);
  }
  state.calendar.sort((a, b) => a.id.localeCompare(b.id));
}
export function seedDemo() {
  const state = { version: 1, revision: 1, calendar: [], dayStates: {}, menus: {}, attendance: {}, bemanning: { driftsleiarar: {}, turnus: { A: {}, B: {}, C: {} } }, templates: [{ effective: '2000-01-01', rows: [
    { id: 'wash', label: 'Wash hands and put on an apron', shift: 'early', weekdays: [1, 2, 3, 4, 5] },
    { id: 'prepare', label: 'Prepare food and set up the serving area', shift: 'early', weekdays: [1, 2, 3, 4, 5] },
    { id: 'serve', label: 'Serve lunch and help customers', shift: 'early', weekdays: [1, 2, 3, 4, 5] },
    { id: 'clean', label: 'Clean worktops and wash equipment', shift: 'late', weekdays: [1, 2, 3, 4, 5] },
    { id: 'stock', label: 'Check supplies and refill the shelves', shift: 'late', weekdays: [1, 2, 3, 4, 5] },
    { id: 'recycle', label: 'Sort waste and leave the kitchen ready', shift: 'late', weekdays: [1, 2, 3, 4, 5] },
    { id: 'inventory', label: 'Count ingredients for next week', shift: 'late', weekdays: [5] },
  ] }] };
  WEEKDAYS.forEach((day, index) => {
    state.bemanning.driftsleiarar[day] = `Student ${String.fromCharCode(65 + index)}`;
    ['A', 'B', 'C'].forEach((rota, r) => { state.bemanning.turnus[rota][day] = { tidleg: `Student ${r * 4 + 1}, Student ${r * 4 + 2}`, seint: `Student ${r * 4 + 3}, Student ${r * 4 + 4}` }; });
  });
  ['2026-10-19', '2026-10-26', '2026-11-02'].forEach((monday, i) => createWeek(state, monday, ['A', 'B', 'C'][i]));
  const meals = ['Vegetable soup with wholemeal rolls', 'Baked potatoes with toppings', 'Wholemeal rolls and fresh fruit', 'Pasta with tomato sauce', 'Vegetable wraps'];
  state.calendar.forEach((day, i) => { state.menus[day.id] = { dagens: meals[i % 5], allergens: i % 5 === 1 ? ['mjolk'] : ['gluten'], alternativ: 'Vegetable salad with rice', alternativ_allergens: [], alternativ_allergenfri: true }; });
  state.dayStates[DEMO_TODAY].checks.wash = true;
  return state;
}
function view(state) {
  const kalender = state.calendar.map(day => {
    const stored = state.dayStates[day.id];
    const base = baseStaff(state, day);
    return { ...day, baseStaffing: base, staffing: stored.staffingOverride || base, staffingCustom: stored.staffingOverride !== null, tasksCustom: stored.tasksCustom,
      taskRows: stored.tasks.map(task => ({ ...task, done: stored.checks[task.id] === true })), substitutes: stored.substitutes };
  });
  return clone({ revision: state.revision, today: DEMO_TODAY, serverTime: DEMO_TIME, kalender, menus: state.menus, attendance: state.attendance,
    bemanning: state.bemanning, templateRows: templateFor(state, TOMORROW), templateEffective: TOMORROW });
}
const RULES = {
  tilsett: ['task_check'],
  driftsleiar: ['attendance', 'menu_day', 'vikar_add', 'vikar_remove'],
  laerar: ['task_check', 'attendance', 'menu_day', 'vikar_add', 'vikar_remove', 'day_staffing', 'day_tasks', 'task_templates', 'calendar_day', 'create_week', 'delete_week', 'staff_templates'],
};
function mutate(state, role, payload) {
  const { type } = payload;
  if (!RULES[role]?.includes(type)) fail('This role cannot perform that action.', 403);
  if (payload.revision !== state.revision) fail('The demo changed in another view. Your draft is kept. Load the latest version and compare before saving.', 409);
  let day, stored;
  if (['task_check', 'attendance', 'menu_day', 'vikar_add', 'vikar_remove', 'day_staffing', 'day_tasks', 'calendar_day'].includes(type)) {
    const id = date(payload.dateId);
    day = state.calendar.find(d => d.id === id);
    if (!day) fail('This day is no longer in the plan.');
    stored = state.dayStates[id];
  }
  switch (type) {
    case 'task_check':
      if (!canCheck(day, role, new Date(DEMO_TIME))) fail('Employees can check tasks on the open demonstration day between 11:00 and 12:00.', 403);
      if (!stored.tasks.some(t => t.id === payload.key) || typeof payload.value !== 'boolean') fail('Choose a valid task.');
      put(stored.checks, payload.key, payload.value); break;
    case 'attendance': {
      const name = text(payload.name, 160);
      if (!name || typeof payload.value !== 'boolean') fail('Choose a name and attendance status.');
      if (!state.attendance[day.id]) state.attendance[day.id] = {};
      put(state.attendance[day.id], name, payload.value); break;
    }
    case 'menu_day': {
      const alternative = text(payload.alternative || '', 2000);
      const allergenList = values => {
        if (!Array.isArray(values) || values.some(v => !Object.hasOwn(ALLERGENS, v))) fail('Choose valid allergens.');
        return [...new Set(values)];
      };
      const allergens = allergenList(payload.allergens), alternativeAllergens = allergenList(payload.alternativeAllergens);
      state.menus[day.id] = { dagens: text(payload.value || '', 2000), allergens, alternativ: alternative,
        alternativ_allergenfri: Boolean(alternative && payload.alternativeFree === true),
        alternativ_allergens: alternative && payload.alternativeFree === true ? [] : alternativeAllergens }; break;
    }
    case 'vikar_add': case 'vikar_remove': {
      const name = text(payload.name, 160), shift = payload.shift;
      if (!name || !['tidleg', 'seint'].includes(shift)) fail('Choose a name and shift.');
      stored.substitutes[shift] = stored.substitutes[shift].filter(n => n !== name);
      if (type === 'vikar_add') stored.substitutes[shift].push(name);
      break;
    }
    case 'day_staffing':
      stored.staffingOverride = payload.reset === true ? null : { leader: text(payload.leader || '', 160), early: staffNames(payload.early), late: staffNames(payload.late) }; break;
    case 'day_tasks':
      stored.tasks = payload.reset === true ? tasksFor(state, day.id) : taskRows(payload.rows);
      stored.tasksCustom = payload.reset !== true; break;
    case 'task_templates': {
      const rows = taskRows(payload.rows, true);
      state.templates = state.templates.filter(t => t.effective !== TOMORROW);
      state.templates.push({ effective: TOMORROW, rows });
      for (const [id, entry] of Object.entries(state.dayStates)) if (id >= TOMORROW && !entry.tasksCustom) entry.tasks = tasksFor(state, id);
      break;
    }
    case 'calendar_day':
      if (!['open', 'closed'].includes(payload.status)) fail('Choose open or closed.');
      day.status = payload.status; day.merknad = text(payload.note || ''); break;
    case 'create_week': {
      const monday = date(payload.monday);
      if (weekday(monday) !== 1 || !['A', 'B', 'C', 'Holiday'].includes(payload.turnus)) fail('Choose a Monday and a valid rota.');
      if (state.calendar.some(d => d.id >= monday && d.id <= addDays(monday, 4))) fail('This week already exists. Open a day to edit it.');
      createWeek(state, monday, payload.turnus); break;
    }
    case 'delete_week': {
      const monday = date(payload.monday);
      if (weekday(monday) !== 1) fail('Choose a Monday.');
      state.calendar = state.calendar.filter(d => d.id < monday || d.id > addDays(monday, 4)); break;
    }
    case 'staff_templates': {
      const keys = WEEKDAYS.flatMap(d => [`driftsleiar_${d}`, ...['A', 'B', 'C'].flatMap(t => [`turnus_${t}_${d}_tidleg`, `turnus_${t}_${d}_seint`])]);
      if (!Array.isArray(payload.items) || payload.items.length !== 35 || new Set(payload.items.map(i => i.key)).size !== 35 || payload.items.some(i => !keys.includes(i.key))) fail('Complete all staffing fields.');
      const values = Object.fromEntries(payload.items.map(item => [item.key, text(item.value || '', item.key.startsWith('driftsleiar_') ? 160 : 4000)]));
      state.calendar.filter(d => d.id <= DEMO_TODAY).forEach(d => { if (state.dayStates[d.id].staffingOverride === null) state.dayStates[d.id].staffingOverride = baseStaff(state, d); });
      WEEKDAYS.forEach(d => {
        state.bemanning.driftsleiarar[d] = values[`driftsleiar_${d}`];
        ['A', 'B', 'C'].forEach(t => { state.bemanning.turnus[t][d] = { tidleg: names(values[`turnus_${t}_${d}_tidleg`]).join(', '), seint: names(values[`turnus_${t}_${d}_seint`]).join(', ') }; });
      }); break;
    }
  }
  state.revision++;
}

export function createDemoStore(getStorage = () => window.localStorage) {
  let memory = seedDemo(), storage, available = true, message = '';
  const fallback = () => { available = false; message = 'Browser storage is unavailable. Changes last while this page stays open; you can still switch roles.'; };
  try { storage = getStorage(); if (!storage) fallback(); } catch { fallback(); }
  const read = () => {
    if (available) {
      try {
        const raw = storage.getItem(STORAGE_KEY);
        if (raw) {
          const saved = JSON.parse(raw);
          if (saved.version !== 1 || !Number.isInteger(saved.revision) || !Array.isArray(saved.calendar) || !saved.dayStates || !Array.isArray(saved.templates) || !saved.templates.length || !saved.bemanning || !saved.menus || !saved.attendance) throw new Error('Invalid saved demonstration.');
          // Build the view before adopting persisted data, so damaged saves recover.
          view(saved); memory = saved;
        }
      } catch (error) {
        if (error instanceof SyntaxError || error.message === 'Invalid saved demonstration.' || error instanceof TypeError) {
          memory = seedDemo(); message = 'Saved demo data could not be read. Start data has been restored.';
          try { storage.setItem(STORAGE_KEY, JSON.stringify(memory)); } catch { fallback(); }
        } else fallback();
      }
    }
    return clone(memory);
  };
  const commit = state => {
    memory = clone(state);
    if (available) try { storage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch { fallback(); }
  };
  return {
    get storageMessage() { return message; },
    request(role, payload) {
      if (!RULES[role]) fail('Choose a demonstration role.');
      if (payload.action === 'load') return view(read());
      if (payload.action !== 'save') fail('Unknown demonstration action.');
      const state = read(); mutate(state, role, payload); commit(state); return view(state);
    },
    reset() { const state = seedDemo(); state.revision = read().revision + 1; commit(state); return view(state); },
  };
}
