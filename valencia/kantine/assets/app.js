import { WEEKDAYS, SHIFTS, ALLERGENS, esc, names, displayDate, osloClock, canCheck, groupWeeks, uid } from './model.js';

let role = 'laerar';
let teacher = role === 'laerar';
let manager = role !== 'tilsett';
let roleLabel = { laerar: 'Teacher', driftsleiar: 'Operations manager', tilsett: 'Employee' }[role];
import { createDemoStore, DEMO_TIME } from './demo-data.js';
const demoStore = createDemoStore();
const updateStorageMessage = () => { document.querySelector('#storage-message').textContent = demoStore.storageMessage; };
const roleIds = { teacher: 'laerar', manager: 'driftsleiar', employee: 'tilsett' };
const root = document.querySelector('#app');
const dialog = document.querySelector('#editor');
const clone = value => JSON.parse(JSON.stringify(value));
const button = (action, text, attributes = '', css = '') => `<button type="button" data-action="${action}" ${attributes} class="${css}">${text}</button>`;
const field = (name, label, value = '', type = 'text', extra = '') => `<label>${label}<input name="${name}" type="${type}" value="${esc(value)}" ${extra}></label>`;
const textarea = (name, label, value = '', extra = '') => `<label><span id="label-${name}">${label}</span><textarea name="${name}" aria-labelledby="label-${name}" ${extra}>${esc(value)}</textarea></label>`;
const badge = (text, style = '') => `<span class="badge ${style}">${esc(text)}</span>`;
const taskDays = row => row.weekdays || (row.weekday ? [row.weekday] : [1, 2, 3, 4, 5]);
const app = {
  data: null, date: null, tab: 'today', query: '', edit: null, pending: null,
  busy: false, loading: false, authenticated: false, timer: null, clockOffset: 0,

  async request(payload) { return demoStore.request(role, payload); },
  accept(data) {
    this.data = data; updateStorageMessage();
    this.clockOffset = new Date(data.serverTime).getTime() - Date.now();
    if (!this.date) this.date = data.today;
  },
  now() { return new Date(DEMO_TIME); },
  day() { return this.data.kalender.find(day => day.id === this.date); },
  captureFocus() {
    const active = document.activeElement;
    if (!root.contains(active)) return null;
    if (active.id) return { id: active.id };
    if (!active.dataset.action) return null;
    const keys = ['action', 'key', 'name', 'shift', 'tab', 'date', 'monday', 'role'];
    return { data: Object.fromEntries(keys.filter(key => active.dataset[key] !== undefined).map(key => [key, active.dataset[key]])) };
  },
  restoreFocus(focus) {
    if (!focus) return;
    const target = focus.id
      ? [...root.querySelectorAll('[id]')].find(el => el.id === focus.id)
      : [...root.querySelectorAll('[data-action]')].find(el => Object.entries(focus.data).every(([key, value]) => el.dataset[key] === value));
    const destination = target && !target.disabled ? target : root.querySelector('#main');
    destination?.focus({ preventScroll: true });
  },
  async init() {
    root.addEventListener('click', event => this.click(event));
    dialog.addEventListener('click', event => this.click(event));
    root.addEventListener('submit', event => this.submit(event));
    dialog.addEventListener('submit', event => this.submit(event));
    root.addEventListener('change', event => this.change(event));
    root.addEventListener('input', event => {
      if (event.target.id === 'plan-search') { this.query = event.target.value; this.renderPlanWeeks(); }
    });
    dialog.addEventListener('input', () => { if (this.edit) { this.capture(); this.edit.dirty = true; } });
    dialog.addEventListener('change', () => { if (this.edit) { this.capture(); this.edit.dirty = true; } });
    dialog.addEventListener('cancel', event => { event.preventDefault(); this.closeEditor(); });
    window.addEventListener('beforeunload', event => { if (this.edit?.dirty || this.pending || this.busy) { event.preventDefault(); event.returnValue = ''; } });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) this.refresh(); });
    window.addEventListener('online', () => this.refresh());
    document.querySelector('#reset-demo').addEventListener('click', () => {
      if (!window.confirm('Reset the demonstration and discard your local changes?')) return;
      this.edit = null; this.pending = null; this.busy = false; this.date = null;
      if (dialog.open) dialog.close(); dialog.replaceChildren();
      this.accept(demoStore.reset());
      if (this.authenticated) this.render(); else this.loginView();
    });
    window.addEventListener('storage', () => this.refresh());
    const selectedRole = roleIds[new URLSearchParams(location.search).get('role')];
    if (selectedRole) await this.selectRole(selectedRole); else this.loginView();
    this.schedule();
    setInterval(() => this.updateTimeGate(), 1000);
  },
  schedule() {
    clearTimeout(this.timer);
    const minutes = osloClock(this.now()).minutes;
    let delay = minutes >= 660 && minutes < 720 ? 10000 : 300000;
    if (minutes < 660) delay = Math.min(delay, (660 - minutes) * 60000 - this.now().getSeconds() * 1000);
    this.timer = setTimeout(async () => { await this.refresh(); this.schedule(); }, Math.max(delay, 1000));
  },
  async refresh() {
    if (!this.authenticated || this.busy || this.loading || document.hidden) return;
    if (this.edit || this.pending) { this.updateTimeGate(); return; }
    this.loading = true;
    try {
      const data = await this.request({ action: 'load' });
      // A dialog may have opened while the request was in flight.
      if (this.edit || this.pending || this.busy) return;
      const active = document.activeElement;
      const isInput = ['INPUT', 'SELECT', 'TEXTAREA'].includes(active?.tagName);
      if (!isInput) {
        this.accept(data); this.render();
      }
      this.status('Ready');
    } catch (error) { if (this.authenticated) this.status('Could not refresh - retrying'); }
    finally { this.loading = false; }
  },
  status(text) { const el = document.querySelector('#sync-status'); if (el) el.textContent = text; },
  notice(text = '') {
    const el = document.querySelector('#notice');
    if (el) { el.textContent = text; el.classList.toggle('error', Boolean(text)); }
  },
  loginView(message = '') {
    if (dialog.open) dialog.close(); dialog.replaceChildren(); updateStorageMessage();
    root.innerHTML = `<main id="main" class="role-picker" tabindex="-1"><p class="eyebrow">School canteen operations</p><h1>Try a working day</h1><p class="muted">Choose a role to explore the same plan from three perspectives. Switch roles at any time to see your changes.</p><div class="role-options">${[['laerar','Teacher','Plan the weeks, manage staffing and adapt daily routines.'],['driftsleiar','Operations manager','Update the menu, record absence and arrange substitutes.'],['tilsett','Employee','Follow the daily checklist and mark tasks as completed.']].map(([id,label,description]) => button('choose-role', `<strong>${label}</strong><span>${description}</span>`, `data-role="${id}"`)).join('')}</div><p class="notice error" role="alert">${esc(message)}</p></main>`;
  },
  async selectRole(nextRole) {
    if (!['laerar','driftsleiar','tilsett'].includes(nextRole)) return;
    role = nextRole; teacher = role === 'laerar'; manager = role !== 'tilsett';
    roleLabel = { laerar: 'Teacher', driftsleiar: 'Operations manager', tilsett: 'Employee' }[role];
    this.date = null; this.tab = 'today'; this.query = ''; this.edit = null; this.pending = null;
    this.accept(await this.request({ action: 'load' })); this.authenticated = true;
    const url = new URL(location.href); url.searchParams.set('role', Object.keys(roleIds).find(id => roleIds[id] === role)); history.replaceState(null,'',url);
    this.render(); document.querySelector('#main').focus();
  },
  render() {
    if (!this.authenticated) return;
    const focus = this.captureFocus();
    root.innerHTML = `<header class="topbar"><div class="topbar-inner"><div class="brand"><span class="brand-mark" aria-hidden="true">K</span><div><strong>CanteenWeek</strong><small>${roleLabel} · Vocational learning</small></div></div><div class="row"><span id="sync-status" class="status" role="status">Ready</span>${button('refresh', 'Refresh', '', 'quiet')}${button('logout', 'Change role', '', 'quiet')}</div></div></header><div class="shell"><aside class="sidebar"><nav class="navigation" aria-label="Main navigation">${[['today', 'Daily operations'], ...(manager ? [['plan', teacher ? 'Planning' : 'Plan overview']] : []), ...(teacher ? [['setup', 'Setup']] : [])].map(([id, label], i) => button('tab', `<span class="nav-number" aria-hidden="true">0${i + 1}</span>${label}`, `data-tab="${id}" ${this.tab === id ? 'aria-current="page"' : ''}`)).join('')}</nav><div class="sidebar-note">Both shifts<br><strong>11.00–12.00</strong><br>Plan together.<br>Take one task at a time.</div></aside><main id="main" class="main" tabindex="-1"><div id="notice" class="notice" role="alert"></div>${this.tab === 'today' ? this.todayView() : this.tab === 'plan' ? this.planView() : this.setupView()}</main></div>`;
    if (this.tab === 'plan') this.renderPlanWeeks();
    this.updateTimeGate();
    this.restoreFocus(focus);
  },
  todayView() {
    const day = this.day();
    const heading = `<div class="page-heading"><div><p class="eyebrow">${esc(displayDate(this.date, { weekday: 'long', year: 'numeric' }))}</p><h1>${role === 'tilsett' ? 'Ready for a good working day' : 'Daily operations'}</h1><p>${role === 'tilsett' ? 'Find your shift and check tasks as you finish them.' : 'People, food and tasks in one place.'}</p></div>${day ? badge(day.status === 'open' ? 'Canteen open' : 'Canteen closed', day.status === 'closed' ? 'closed' : '') : ''}</div><div class="toolbar">${field('selected-date', 'Working day', this.date, 'date', 'id="selected-date"')}${button('today', 'Today')}${teacher && day ? button('edit-calendar', 'Edit working day') : ''}</div>`;
    if (!day) {
      const next = this.data.kalender.find(d => d.id > this.date && d.status === 'open');
      return `${heading}<div class="card empty"><h2>No plan for this day</h2><p class="muted">Choose another date${teacher ? ', or create a week under Planning' : ''}.</p>${next ? button('date', `Next working day · ${esc(displayDate(next.id))}`, `data-date="${next.id}"`, 'primary') : ''}</div>`;
    }
    const checked = day.taskRows.filter(task => task.done).length;
    const staffCount = day.staffing.early.length + day.staffing.late.length + day.substitutes.tidleg.length + day.substitutes.seint.length;
    const summary = `<div class="summary"><div class="summary-item"><small>Operations manager</small><strong>${esc(day.staffing.leader || 'Not assigned')}</strong></div><div class="summary-item"><small>Shift staffing</small><strong>${staffCount} assignments</strong></div><div class="summary-item"><small>Completed tasks</small><strong>${checked} of ${day.taskRows.length}</strong></div></div>`;
    const next = this.data.kalender.find(d => d.id > this.date && d.status === 'open');
    const closed = day.status === 'closed' ? `<div class="notice"><p><strong>The canteen is closed.</strong> The plan is visible, but employees cannot check tasks.${day.merknad ? ` ${esc(day.merknad)}` : ''}</p>${next ? button('date', `Next working day · ${esc(displayDate(next.id))}`, `data-date="${next.id}"`) : ''}</div>` : day.merknad ? `<div class="notice">${esc(day.merknad)}</div>` : '';
    const taskPanel = this.tasksView(day);
    const otherPanels = `<div class="stack">${this.menuView(day)}${this.staffView(day)}</div>`;
    return `${heading}${closed}${summary}<div class="grid drift-grid">${role === 'tilsett' ? taskPanel + otherPanels : otherPanels + taskPanel}</div>`;
  },
  menuView(day) {
    const menu = this.data.menus[day.id] || {};
    const allergens = ids => (ids || []).map(id => ALLERGENS[id] || id).join(', ');
    return `<section class="card"><div class="card-head"><div><p class="eyebrow">On the plate</p><h2>Today’s menu</h2></div>${manager ? button('edit-menu', 'Edit menu') : ''}</div><p class="menu-text">${esc(menu.dagens || 'No menu entered')}</p>${menu.allergens?.length ? `<p class="allergens"><strong>Allergens and dietary information:</strong> ${esc(allergens(menu.allergens))}</p>` : ''}${menu.alternativ ? `<div class="menu-alternative"><h3>Alternative dish</h3><p>${esc(menu.alternativ)}</p>${menu.alternativ_allergenfri ? '<p class="allergens">Marked allergen-free by the kitchen</p>' : menu.alternativ_allergens?.length ? `<p class="allergens"><strong>Allergens and dietary information:</strong> ${esc(allergens(menu.alternativ_allergens))}</p>` : ''}</div>` : ''}</section>`;
  },
  tasksView(day) {
    const total = day.taskRows.length;
    const done = day.taskRows.filter(t => t.done).length;
    return `<section class="card"><div class="card-head"><div><p class="eyebrow">Step by step</p><h2>Checklists</h2></div>${teacher ? button('edit-tasks', 'Adapt this day') : ''}</div><div class="row spread"><small>${done} of ${total} completed</small>${badge(day.tasksCustom ? 'Adapted for this day' : 'Standard routines')}</div><progress class="progress" value="${done}" max="${Math.max(total, 1)}" aria-label="Completed tasks"></progress>${role === 'tilsett' ? '<p id="time-gate" class="muted"></p>' : ''}${Object.entries(SHIFTS).map(([shift, label]) => `<h3 class="subheading">${label} <small>11.00–12.00</small></h3>${day.taskRows.filter(t => t.shift === shift).map(t => `<label class="check-row ${t.done ? 'done' : ''}"><input type="checkbox" data-action="check" data-key="${esc(t.id)}" ${t.done ? 'checked' : ''} ${canCheck(day, role, this.now()) ? '' : 'disabled'}><span>${esc(t.label)}</span></label>`).join('') || '<p class="muted">No tasks for this shift.</p>'}`).join('')}${teacher && day.tasksCustom ? `<div class="staff-footer">${button('reset-tasks', 'Restore standard routines', '', 'quiet')}</div>` : ''}</section>`;
  },
  staffView(day) {
    const attendance = this.data.attendance[day.id] || {};
    const person = (name, substitute, shift) => {
      const present = attendance[name] !== false;
      return `<div class="staff-person ${present ? '' : 'absent'} ${substitute ? 'substitute' : ''}"><span>${esc(name)}${substitute ? '<small> · Substitute</small>' : ''}</span><div class="row">${manager ? button('attendance', present ? 'Present' : 'Absent', `data-name="${esc(name)}" data-present="${present}" aria-label="${present ? 'Record absence for' : 'Mark present:'} ${esc(name)}"`) : badge(present ? 'Present' : 'Absent', present ? '' : 'absent')}${substitute && manager ? button('remove-sub', 'Remove', `data-name="${esc(name)}" data-shift="${shift}" aria-label="Remove substitute ${esc(name)}"`, 'quiet') : ''}</div></div>`;
    };
    return `<section class="card"><div class="card-head"><div><p class="eyebrow">Working together</p><h2>Staffing</h2></div>${teacher ? button('edit-staff', 'Edit staffing') : ''}</div>${badge(day.staffingCustom ? 'Adapted for this day' : `Base rota ${day.turnusType}`)}${Object.entries(SHIFTS).map(([shift, label]) => { const subShift = shift === 'early' ? 'tidleg' : 'seint'; return `<h3 class="subheading">${label} <small>11.00–12.00</small></h3>${day.staffing[shift].map(name => person(name, false, subShift)).join('') || '<p class="muted">No regular staff assigned.</p>'}${day.substitutes[subShift].map(name => person(name, true, subShift)).join('')}${manager ? `<div class="staff-footer">${button('add-sub', '+ Add substitute', `data-shift="${subShift}"`, 'quiet')}</div>` : ''}`; }).join('')}${teacher && day.staffingCustom ? `<div class="staff-footer">${button('reset-staff', 'Restore base rota', '', 'quiet')}</div>` : ''}</section>`;
  },
  planView() {
    return `<div class="page-heading"><div><p class="eyebrow">Week by week</p><h1>${teacher ? 'Planning' : 'Plan overview'}</h1><p>Open a day to see its menu, staffing and tasks.</p></div>${teacher ? button('create-week', '+ Create week', '', 'primary') : ''}</div><div class="toolbar">${field('search', 'Search the plan', this.query, 'search', 'id="plan-search" placeholder="Name, menu or date"')}</div><div id="plan-weeks"></div>`;
  },
  renderPlanWeeks() {
    const container = document.querySelector('#plan-weeks'); if (!container) return;
    const query = this.query.toLocaleLowerCase('en-GB');
    const days = this.data.kalender.filter(day => !query || [day.id, displayDate(day.id, { weekday: 'long', year: 'numeric' }), this.data.menus[day.id]?.dagens, this.data.menus[day.id]?.alternativ, day.staffing.leader, ...day.staffing.early, ...day.staffing.late, ...day.substitutes.tidleg, ...day.substitutes.seint].join(' ').toLocaleLowerCase('en-GB').includes(query));
    container.innerHTML = groupWeeks(days).map(([monday, week]) => `<section class="card week"><div class="week-head"><div><h2>Week ${week[0].veke} <small>· ${esc(displayDate(monday, { year: 'numeric' }))}</small></h2><small>Rota ${esc(week[0].turnusType)}</small></div>${teacher ? button('delete-week', 'Remove week', `data-monday="${monday}"`, 'quiet danger') : ''}</div><div class="day-grid">${week.map(day => button('date', `<strong>${esc(displayDate(day.id, { weekday: 'short', month: 'numeric' }))}</strong><span>${esc(this.data.menus[day.id]?.dagens || 'Menu not set')}</span><small>${esc(day.staffing.leader || 'Manager not assigned')}</small>${badge(day.status === 'open' ? 'Open' : 'Closed', day.status === 'closed' ? 'closed' : '')}`, `data-date="${day.id}"`, `day-card ${day.id === this.data.today ? 'today' : ''}`)).join('')}</div></section>`).join('') || '<div class="card empty"><h2>No days to show</h2><p class="muted">Try another search or create a new week.</p></div>';
  },
  setupView() {
    return `<div class="page-heading"><div><p class="eyebrow">Good routines</p><h1>Setup</h1><p>Build the base plan here. Adapt individual days under Daily operations.</p></div></div><div class="setup-links">${button('edit-template', '<strong>Shared routines and weekday tasks</strong><small>Tasks for both shifts, with routines for selected weekdays. Changes apply from the following day.</small>')}${button('edit-staff-template', '<strong>Rota and regular operations managers</strong><small>Staffing for A, B and C weeks, and a manager for each weekday. Changes apply to future days using the base rota.</small>')}</div><div class="notice" style="margin-top:1.5rem"><strong>Working hours and checklists</strong><br>Both shifts run from 11:00 to 12:00. Employees check tasks on the working day; the teacher can make corrections later.</div>`;
  },
  updateTimeGate() {
    if (!this.data || this.tab !== 'today') return;
    const day = this.day(); if (!day) return;
    const allowed = canCheck(day, role, this.now()) && !this.busy && !this.pending;
    root.querySelectorAll('[data-action="check"]').forEach(el => { el.disabled = !allowed; });
    const message = document.querySelector('#time-gate');
    if (message) message.textContent = allowed ? 'You can check tasks now. In normal use, checking closes at 12:00.' : 'Checking is available from 11:00 to 12:00 on an open working day.';
  },
  openEditor(kind, fields = {}, rows = null, title = '') {
    this.edit = { kind, fields: clone(fields), rows: rows ? clone(rows) : null, revision: this.data.revision, dateId: this.date, dirty: false, title, returnFocus: this.captureFocus() };
    this.renderEditor(); dialog.showModal();
  },
  capture() {
    const form = dialog.querySelector('#edit-form'); if (!form || !this.edit) return;
    if (this.edit.kind === 'confirm') return;
    const values = new FormData(form);
    this.edit.fields = Object.fromEntries(values);
    if (this.edit.kind === 'menu') {
      this.edit.fields.allergens = values.getAll('allergens');
      this.edit.fields.alternativeAllergens = values.getAll('alternativeAllergens');
      this.edit.fields.alternativeFree = values.has('alternativeFree');
    }
    if (this.edit.rows) this.edit.rows = [...form.querySelectorAll('[data-row-id]')].map(row => ({ id: row.dataset.rowId, label: row.querySelector('[data-col="label"]').value, shift: row.querySelector('[data-col="shift"]').value, ...(this.edit.kind === 'template' ? { weekdays: [...row.querySelectorAll('[data-col="weekday"]:checked')].map(input => Number(input.value)) } : {}) }));
    this.validateTaskDays();
  },
  validateTaskDays() {
    dialog.querySelectorAll('.weekday-field').forEach(group => {
      group.querySelector('input').setCustomValidity(group.querySelector('input:checked') ? '' : 'Choose at least one weekday for this task.');
    });
  },
  renderEditor() {
    const edit = this.edit; const f = edit.fields;
    let content = ''; let title = edit.title;
    if (edit.kind === 'menu') {
      title = 'Edit today’s menu';
      const allergenFields = (name, selected) => `<div class="choices">${Object.entries(ALLERGENS).map(([id, label]) => `<label><input type="checkbox" name="${name}" value="${id}" ${(selected || []).includes(id) ? 'checked' : ''}>${label}</label>`).join('')}</div>`;
      content = `${textarea('value', 'Main dish', f.value, 'maxlength="2000"')}<fieldset><legend>Allergens and dietary information</legend>${allergenFields('allergens', f.allergens)}</fieldset>${textarea('alternative', 'Alternative dish', f.alternative, 'maxlength="2000"')}<fieldset><legend>Allergens in the alternative dish</legend><label class="check-row"><input name="alternativeFree" type="checkbox" ${f.alternativeFree ? 'checked' : ''}><span>The alternative is allergen-free</span></label>${allergenFields('alternativeAllergens', f.alternativeAllergens)}</fieldset>`;
    } else if (edit.kind === 'staff') {
      title = 'Staffing for this day';
      content = `<p class="muted">This change applies only to ${esc(displayDate(edit.dateId))}. Enter one name per line or separate with commas. An empty field means an empty shift.</p>${field('leader', 'Operations manager', f.leader, 'text', 'maxlength="160"')}<div class="form-grid">${textarea('early', 'Early shift', f.early)}${textarea('late', 'Late shift', f.late)}</div>`;
    } else if (edit.kind === 'tasks' || edit.kind === 'template') {
      title = edit.kind === 'template' ? 'Shared routines and weekday tasks' : 'Checklist for this day';
      content = `<p class="muted">${edit.kind === 'template' ? `Changes apply from ${esc(displayDate(this.data.templateEffective))}. Today’s list, history and individually adapted days are preserved.` : 'Edit tasks for this date. Completed checkmarks are preserved when tasks are moved.'}</p><div class="stack">${edit.rows.map((row, i) => `<div class="task-editor-row" data-row-id="${esc(row.id)}"><label>Task<input data-col="label" value="${esc(row.label)}" required maxlength="255"></label><label>Shift<select data-col="shift">${Object.entries(SHIFTS).map(([id, label]) => `<option value="${id}" ${row.shift === id ? 'selected' : ''}>${label}</option>`).join('')}</select></label>${edit.kind === 'template' ? `<fieldset class="weekday-field"><legend>When should this task be done?</legend><div class="choices">${WEEKDAYS.map((label, n) => `<label><input type="checkbox" data-col="weekday" value="${n + 1}" ${taskDays(row).includes(n + 1) ? 'checked' : ''}>${label}</label>`).join('')}</div></fieldset>` : ''}<div class="row">${button('move-task', '↑ Up', `data-index="${i}" data-step="-1" aria-label="Move task ${i + 1} up" ${i === 0 ? 'disabled' : ''}`)}${button('move-task', '↓ Down', `data-index="${i}" data-step="1" aria-label="Move task ${i + 1} down" ${i === edit.rows.length - 1 ? 'disabled' : ''}`)}${button('remove-task', 'Remove', `data-index="${i}" aria-label="Remove task ${i + 1}"`, 'danger')}</div></div>`).join('')}</div>${button('add-task', '+ Add task')}`;
    } else if (edit.kind === 'calendar') {
      title = 'Edit working day';
      content = `<label>Status<select name="status"><option value="open" ${f.status === 'open' ? 'selected' : ''}>Canteen open</option><option value="closed" ${f.status === 'closed' ? 'selected' : ''}>Canteen closed</option></select></label>${textarea('note', 'Note', f.note, 'maxlength="255"')}<p class="muted">In the live system, a closed-day note also appears on the public screen. Demo changes stay here. Use a note such as "Holiday" or "Planning day".</p>`;
    } else if (edit.kind === 'week') {
      title = 'Create a week';
      content = `<p class="muted">Choose a Monday. The week number is calculated automatically and five working days are created.</p>${field('monday', 'Monday date', f.monday, 'date', 'required')}<label>Rota<select name="turnus">${['A', 'B', 'C', 'Holiday'].map(t => `<option value="${t}" ${f.turnus === t ? 'selected' : ''}>${t === 'Holiday' ? 'Holiday - all days closed' : `Week ${t}`}</option>`).join('')}</select></label>`;
    } else if (edit.kind === 'sub') {
      title = `Add substitute · ${f.shift === 'tidleg' ? 'early shift' : 'late shift'}`;
      content = `${field('name', 'Substitute name', f.name, 'text', 'required maxlength="160" list="known-names"')}<input type="hidden" name="shift" value="${esc(f.shift)}"><datalist id="known-names">${this.knownNames().map(name => `<option value="${esc(name)}">`).join('')}</datalist>`;
    } else if (edit.kind === 'staff-template') {
      title = 'Rota and regular operations managers';
      content = '<p class="muted">Changes apply to future days using the base rota. Today’s staffing, history and adapted days are preserved. Separate names with commas.</p><fieldset><legend>Operations managerar</legend><div class="form-grid">';
      content += WEEKDAYS.map(day => field(`driftsleiar_${day}`, day, f[`driftsleiar_${day}`], 'text', 'maxlength="160"')).join('') + '</div></fieldset>';
      content += ['A', 'B', 'C'].map(turnus => `<fieldset><legend>Rota ${turnus}</legend>${WEEKDAYS.map(day => `<h3 class="subheading">${day}</h3><div class="form-grid">${field(`turnus_${turnus}_${day}_tidleg`, 'Early shift', f[`turnus_${turnus}_${day}_tidleg`])}${field(`turnus_${turnus}_${day}_seint`, 'Late shift', f[`turnus_${turnus}_${day}_seint`])}</div>`).join('')}</fieldset>`).join('');
    } else if (edit.kind === 'confirm') content = `<p class="confirm-text">${esc(f.message)}</p>`;
    dialog.innerHTML = `<form id="edit-form"><header class="dialog-head"><h2 id="editor-title">${esc(title)}</h2>${button('close-editor', 'Close', 'aria-label="Close dialog"', 'quiet')}</header><div class="dialog-body">${content}<div id="editor-error" class="notice error" role="alert"></div><div id="conflict-review"></div></div><footer class="dialog-actions">${button('close-editor', 'Cancel')}<button type="submit" class="primary">${edit.kind === 'confirm' ? 'Confirm' : 'Save'}</button></footer></form>`;
    this.validateTaskDays();
  },
  knownNames() {
    return [...new Set(this.data.kalender.flatMap(day => [...day.staffing.early, ...day.staffing.late, ...day.substitutes.tidleg, ...day.substitutes.seint]))].sort((a, b) => a.localeCompare(b, 'nn'));
  },
  closeEditor() {
    if (this.busy) return;
    if ((this.edit?.dirty || this.pending) && !window.confirm('Discard the unsaved draft?')) return;
    const focus = this.edit?.returnFocus;
    this.edit = null; this.pending = null; dialog.close(); dialog.replaceChildren(); this.restoreFocus(focus); this.refresh();
  },
  confirm(title, message, payload) {
    this.openEditor('confirm', { message }, null, title); this.edit.payload = payload;
  },
  payload() {
    this.capture(); const e = this.edit, f = e.fields;
    const base = { dateId: e.dateId, revision: e.revision };
    switch (e.kind) {
      case 'menu': return { ...base, type: 'menu_day', ...f };
      case 'staff': return { ...base, type: 'day_staffing', leader: f.leader, early: names(f.early), late: names(f.late) };
      case 'tasks': return { ...base, type: 'day_tasks', rows: e.rows };
      case 'template': return { ...base, type: 'task_templates', rows: e.rows };
      case 'calendar': return { ...base, type: 'calendar_day', ...f };
      case 'week': return { ...base, type: 'create_week', ...f };
      case 'sub': return { ...base, type: 'vikar_add', ...f };
      case 'staff-template': return { ...base, type: 'staff_templates', items: Object.entries(f).map(([key, value]) => ({ key, value })) };
      case 'confirm': return { ...base, ...e.payload };
    }
  },
  async save(payload) {
    if (this.busy) return;
    const focus = this.edit ? this.edit.returnFocus : this.captureFocus();
    let saved = false;
    this.pending = payload; this.busy = true; this.status('Saving locally …');
    dialog.querySelectorAll('button,input,textarea,select').forEach(el => { el.disabled = true; });
    this.updateTimeGate();
    try {
      const data = await this.request({ action: 'save', ...payload });
      this.accept(data); this.pending = null; this.edit = null; dialog.close(); dialog.replaceChildren(); this.render(); this.status('Saved locally');
      saved = true;
    } catch (error) {
      if (this.authenticated) {
        if (!this.edit) { this.openEditor('confirm', { message: 'The action has not been saved. Try again or cancel.' }, null, 'Saving stopped'); this.edit.payload = payload; }
        const errorEl = document.querySelector('#editor-error'); if (errorEl) errorEl.textContent = error.message;
        if (error.status === 409) document.querySelector('#conflict-review').innerHTML = button('review-conflict', 'Load latest version to compare');
      }
      this.status('Not saved');
    } finally {
      this.busy = false;
      // Rerendering would erase drafts; only release controls we disabled.
      dialog.querySelectorAll('button,input,textarea,select').forEach(el => { el.disabled = false; });
      this.updateTimeGate();
      if (saved) this.restoreFocus(focus);
    }
  },
  describe(data, payload) {
    const day = data.kalender.find(d => d.id === payload.dateId);
    if (payload.type === 'menu_day') { const m = data.menus[payload.dateId] || {}; return `Main dish: ${m.dagens || '—'}\nAllergen: ${(m.allergens || []).map(id => ALLERGENS[id]).join(', ')}\nAlternative: ${m.alternativ || '—'}\nAlternative allergens: ${m.alternativ_allergenfri ? 'Marked allergen-free' : (m.alternativ_allergens || []).map(id => ALLERGENS[id]).join(', ')}`; }
    if (payload.type === 'task_templates' || payload.type === 'day_tasks' || payload.type === 'task_check') return (payload.type === 'task_templates' ? data.templateRows : day?.taskRows || []).map(t => `${SHIFTS[t.shift]}${payload.type === 'task_templates' ? ` · ${taskDays(t).map(n => WEEKDAYS[n - 1]).join(', ')}` : ''}: ${t.label}${t.done ? ' (completed)' : ''}`).join('\n');
    if (payload.type === 'day_staffing') return day ? `Operations manager: ${day.staffing.leader}\nEarly: ${day.staffing.early.join(', ')}\nLate: ${day.staffing.late.join(', ')}` : 'The day has been removed.';
    if (payload.type === 'calendar_day') return day ? `${day.status === 'open' ? 'Open' : 'Closed'}\n${day.merknad}` : 'The day has been removed.';
    if (payload.type === 'attendance') return `${payload.name}: ${data.attendance[payload.dateId]?.[payload.name] === false ? 'Absent' : 'Present'}`;
    if (payload.type.startsWith('vikar_')) return day ? `Substitutes: ${day.substitutes.tidleg.join(', ')} (early); ${day.substitutes.seint.join(', ')} (late)` : 'The day has been removed.';
    if (payload.type === 'staff_templates') return Object.entries(data.bemanning.driftsleiarar).map(([d, n]) => `${d}: ${n}`).join('\n') + '\n' + ['A', 'B', 'C'].flatMap(t => WEEKDAYS.map(d => `${t} · ${d}: ${data.bemanning.turnus[t]?.[d]?.tidleg || '—'} / ${data.bemanning.turnus[t]?.[d]?.seint || '—'}`)).join('\n');
    return data.kalender.filter(d => d.weekStart === payload.monday).map(d => `${d.id}: ${d.status === 'open' ? 'Open' : 'Closed'}, turnus ${d.turnusType}`).join('\n') || 'The week does not exist.';
  },
  async reviewConflict() {
    try {
      this.capture();
      const latest = await this.request({ action: 'load' });
      if (!this.edit) return;
      this.edit.reviewRevision = latest.revision;
      document.querySelector('#conflict-review').innerHTML = `<h3>Latest saved version</h3><div class="comparison">${esc(this.describe(latest, this.pending || this.payload()))}</div><p class="muted">Your draft is still in the fields above. Compare and adjust it before saving.</p>${button('save-reviewed', 'Save after comparison', '', 'primary')}`;
    } catch (error) { const el = document.querySelector('#editor-error'); if (el) el.textContent = error.message; }
  },
  async submit(event) {
    event.preventDefault();
    if (event.target.id === 'edit-form') await this.save(this.payload());
  },
  change(event) {
    if (event.target.id === 'selected-date') {
      if (event.target.value) { this.date = event.target.value; this.render(); }
    } else if (event.target.dataset.action === 'check') {
      const checked = event.target.checked; event.target.checked = !checked;
      this.quick({ type: 'task_check', key: event.target.dataset.key, value: checked });
    }
  },
  quick(payload) { if (!this.busy && !this.pending) this.save({ dateId: this.date, revision: this.data.revision, ...payload }); },
  async click(event) {
    const target = event.target.closest('[data-action]'); if (!target || target.disabled) return;
    const action = target.dataset.action;
    if (action === 'check' || this.busy) return;
    const day = this.data ? this.day() : null;
    switch (action) {
      case 'tab': this.tab = target.dataset.tab; this.render(); document.querySelector('#main').focus(); break;
      case 'date': this.date = target.dataset.date; this.tab = 'today'; this.render(); document.querySelector('#main').focus(); break;
      case 'today': this.date = osloClock(this.now()).date; this.render(); break;
      case 'refresh': await this.refresh(); break;
      case 'choose-role': await this.selectRole(target.dataset.role); break;
      case 'logout': {
        if ((this.edit?.dirty || this.pending) && !confirm('Discard the unsaved draft and switch roles?')) return;
        this.edit = null; this.pending = null; this.data = null; this.authenticated = false;
        const url = new URL(location.href); url.searchParams.delete('role'); history.replaceState(null,'',url);
        this.loginView(); document.querySelector('#main').focus(); break;
      }
      case 'edit-menu': { const m = this.data.menus[this.date] || {}; this.openEditor('menu', { value: m.dagens || '', alternative: m.alternativ || '', allergens: m.allergens || [], alternativeAllergens: m.alternativ_allergens || [], alternativeFree: Boolean(m.alternativ_allergenfri) }); break; }
      case 'edit-staff': this.openEditor('staff', { leader: day.staffing.leader, early: day.staffing.early.join('\n'), late: day.staffing.late.join('\n') }); break;
      case 'edit-tasks': this.openEditor('tasks', {}, day.taskRows); break;
      case 'edit-template': this.openEditor('template', {}, this.data.templateRows); break;
      case 'edit-calendar': this.openEditor('calendar', { status: day.status, note: day.merknad }); break;
      case 'create-week': this.openEditor('week', { monday: '', turnus: 'A' }); break;
      case 'add-sub': this.openEditor('sub', { name: '', shift: target.dataset.shift }); break;
      case 'attendance': this.quick({ type: 'attendance', name: target.dataset.name, value: target.dataset.present !== 'true' }); break;
      case 'remove-sub': this.quick({ type: 'vikar_remove', name: target.dataset.name, shift: target.dataset.shift }); break;
      case 'reset-staff': this.confirm('Restore base rota', 'Replace this day’s staffing with the base rota? Substitutes are preserved.', { type: 'day_staffing', reset: true }); break;
      case 'reset-tasks': this.confirm('Restore standard routines', 'Replace this day’s list with the routines for its date? Additional tasks are removed. Checks for tasks that still exist are preserved.', { type: 'day_tasks', reset: true }); break;
      case 'delete-week': this.confirm('Remove the week from the plan', `Remove the week starting ${displayDate(target.dataset.monday, { year: 'numeric' })}? Menus and historical records are preserved if you recreate this week.`, { type: 'delete_week', monday: target.dataset.monday }); break;
      case 'edit-staff-template': {
        const fields = {}; const staffing = this.data.bemanning;
        for (const d of WEEKDAYS) {
          fields[`driftsleiar_${d}`] = staffing.driftsleiarar[d] || '';
          for (const t of ['A', 'B', 'C']) for (const s of ['tidleg', 'seint']) fields[`turnus_${t}_${d}_${s}`] = staffing.turnus[t]?.[d]?.[s] || '';
        }
        this.openEditor('staff-template', fields); break;
      }
      case 'close-editor': this.closeEditor(); break;
      case 'add-task': this.capture(); this.edit.rows.push({ id: uid(), label: '', shift: 'early', ...(this.edit.kind === 'template' ? { weekdays: [1, 2, 3, 4, 5] } : {}) }); this.edit.dirty = true; this.renderEditor(); dialog.querySelector('[data-row-id]:last-child input').focus(); break;
      case 'remove-task': this.capture(); this.edit.rows.splice(Number(target.dataset.index), 1); this.edit.dirty = true; this.renderEditor(); break;
      case 'move-task': {
        this.capture(); const from = Number(target.dataset.index), to = from + Number(target.dataset.step);
        if (to < 0 || to >= this.edit.rows.length) return;
        const [row] = this.edit.rows.splice(from, 1); this.edit.rows.splice(to, 0, row); this.edit.dirty = true; this.renderEditor();
        dialog.querySelectorAll('[data-col="label"]')[to].focus(); break;
      }
      case 'review-conflict': await this.reviewConflict(); break;
      case 'save-reviewed': { const payload = this.payload(); payload.revision = this.edit.reviewRevision; this.edit.revision = payload.revision; await this.save(payload); break; }
    }
  },
};
app.init();
