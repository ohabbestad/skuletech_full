import { WEEKDAYS, SHIFTS, ALLERGENS, esc, names, displayDate, osloClock, canCheck, groupWeeks, uid } from './model.js';

const role = document.body.dataset.role;
const teacher = role === 'laerar';
const manager = role !== 'tilsett';
const roleLabel = { laerar: 'Lærar', driftsleiar: 'Driftsleiar', tilsett: 'Tilsett' }[role];
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

  async request(payload) {
    let response;
    try {
      response = await fetch('api/index.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ role, ...payload }), signal: AbortSignal.timeout(20000) });
    } catch {
      throw new Error('Kunne ikkje nå serveren. Kontroller nettet og prøv igjen.');
    }
    let data;
    try { data = await response.json(); } catch { throw new Error('Serveren gav ikkje eit gyldig svar. Prøv igjen.'); }
    if (!response.ok || data.error) {
      const error = new Error(data.error || 'Handlinga kunne ikkje fullførast.');
      error.status = response.status;
      if (response.status === 401) { this.authenticated = false; this.loginView(error.message); }
      throw error;
    }
    return data;
  },
  accept(data) {
    this.data = data;
    this.clockOffset = new Date(data.serverTime).getTime() - Date.now();
    if (!this.date) this.date = data.today;
  },
  now() { return new Date(Date.now() + this.clockOffset); },
  day() { return this.data.kalender.find(day => day.id === this.date); },
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
    try { this.accept(await this.request({ action: 'load' })); this.authenticated = true; this.render(); }
    catch (error) { this.loginView(error.status === 401 ? '' : error.message); }
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
      const focus = active?.dataset.action ? { action: active.dataset.action, key: active.dataset.key, name: active.dataset.name } : null;
      const isInput = ['INPUT', 'SELECT', 'TEXTAREA'].includes(active?.tagName);
      if (!isInput) {
        this.accept(data); this.render();
        if (focus) [...root.querySelectorAll('[data-action]')].find(el => el.dataset.action === focus.action && el.dataset.key === focus.key && el.dataset.name === focus.name)?.focus({ preventScroll: true });
      }
      this.status('Oppdatert');
    } catch (error) { if (this.authenticated) this.status('Frakopla – prøver igjen'); }
    finally { this.loading = false; }
  },
  status(text) { const el = document.querySelector('#sync-status'); if (el) el.textContent = text; },
  notice(text = '') {
    const el = document.querySelector('#notice');
    if (el) { el.textContent = text; el.classList.toggle('error', Boolean(text)); }
  },
  loginView(message = '') {
    if (dialog.open) dialog.close();
    dialog.replaceChildren();
    root.innerHTML = `<main id="main" class="login-wrap"><section class="card login"><div class="brand"><span class="brand-mark" aria-hidden="true">K</span><div><strong>KantineVeke</strong><small>Ein god arbeidsdag, saman.</small></div></div><p class="eyebrow">${roleLabel}</p><h1>Velkomen til kantina</h1><p class="muted">Logg inn for å ${teacher ? 'planleggje og følgje opp drifta' : manager ? 'halde oversikt over arbeidsdagen' : 'sjå og krysse av oppgåvene'}.</p><form id="login-form">${field('password', 'Passord', '', 'password', 'required autocomplete="current-password"')}<p id="login-error" class="notice error" role="alert">${esc(message)}</p><button class="primary" type="submit">Logg inn som ${roleLabel.toLocaleLowerCase('nn-NO')}</button></form><div class="login-links">${Object.entries({ laerar: 'Lærar', driftsleiar: 'Driftsleiar', tilsett: 'Tilsett' }).filter(([id]) => id !== role).map(([id, label]) => `<a href="${id}.html">${label}</a>`).join('')}</div></section></main>`;
  },
  render() {
    if (!this.authenticated) return;
    root.innerHTML = `<header class="topbar"><div class="topbar-inner"><div class="brand"><span class="brand-mark" aria-hidden="true">K</span><div><strong>KantineVeke</strong><small>${roleLabel} · Arbeidslivsfag</small></div></div><div class="row"><span id="sync-status" class="status" role="status">Oppdatert</span>${button('refresh', 'Oppdater', '', 'quiet')}${button('logout', 'Logg ut', '', 'quiet')}</div></div></header><div class="shell"><aside class="sidebar"><nav class="navigation" aria-label="Hovudmeny">${[['today', 'Dagens drift'], ...(manager ? [['plan', teacher ? 'Planlegging' : 'Planoversikt']] : []), ...(teacher ? [['setup', 'Oppsett']] : [])].map(([id, label], i) => button('tab', `<span class="nav-number" aria-hidden="true">0${i + 1}</span>${label}`, `data-tab="${id}" ${this.tab === id ? 'aria-current="page"' : ''}`)).join('')}</nav><div class="sidebar-note">Begge vakter<br><strong>11.00–12.00</strong><br>Planlegg saman.<br>Gjer éi oppgåve om gongen.</div></aside><main id="main" class="main" tabindex="-1"><div id="notice" class="notice" role="alert"></div>${this.tab === 'today' ? this.todayView() : this.tab === 'plan' ? this.planView() : this.setupView()}</main></div>`;
    if (this.tab === 'plan') this.renderPlanWeeks();
    this.updateTimeGate();
  },
  todayView() {
    const day = this.day();
    const heading = `<div class="page-heading"><div><p class="eyebrow">${esc(displayDate(this.date, { weekday: 'long', year: 'numeric' }))}</p><h1>${role === 'tilsett' ? 'Klart for ein god arbeidsdag' : 'Dagens drift'}</h1><p>${role === 'tilsett' ? 'Finn vakta di, og kryss av etter kvart som de blir ferdige.' : 'Menneska, maten og oppgåvene – samla på éin stad.'}</p></div>${day ? badge(day.status === 'open' ? 'Open kantine' : 'Stengd kantine', day.status === 'closed' ? 'closed' : '') : ''}</div><div class="toolbar">${field('selected-date', 'Arbeidsdag', this.date, 'date', 'id="selected-date"')}${button('today', 'I dag')}${teacher && day ? button('edit-calendar', 'Endre arbeidsdag') : ''}</div>`;
    if (!day) {
      const next = this.data.kalender.find(d => d.id > this.date && d.status === 'open');
      return `${heading}<div class="card empty"><h2>Ingen plan for denne dagen</h2><p class="muted">Vel ein annan dato${teacher ? ', eller opprett ei veke under Planlegging' : ''}.</p>${next ? button('date', `Neste arbeidsdag · ${esc(displayDate(next.id))}`, `data-date="${next.id}"`, 'primary') : ''}</div>`;
    }
    const checked = day.taskRows.filter(task => task.done).length;
    const staffCount = day.staffing.early.length + day.staffing.late.length + day.substitutes.tidleg.length + day.substitutes.seint.length;
    const summary = `<div class="summary"><div class="summary-item"><small>Driftsleiar</small><strong>${esc(day.staffing.leader || 'Ikkje sett opp')}</strong></div><div class="summary-item"><small>Bemanning på vaktene</small><strong>${staffCount} oppføringar</strong></div><div class="summary-item"><small>Oppgåver ferdige</small><strong>${checked} av ${day.taskRows.length}</strong></div></div>`;
    const next = this.data.kalender.find(d => d.id > this.date && d.status === 'open');
    const closed = day.status === 'closed' ? `<div class="notice"><p><strong>Kantina er stengd.</strong> Planen er synleg, men tilsette kan ikkje krysse av.${day.merknad ? ` ${esc(day.merknad)}` : ''}</p>${next ? button('date', `Neste arbeidsdag · ${esc(displayDate(next.id))}`, `data-date="${next.id}"`) : ''}</div>` : day.merknad ? `<div class="notice">${esc(day.merknad)}</div>` : '';
    const taskPanel = this.tasksView(day);
    const otherPanels = `<div class="stack">${this.menuView(day)}${this.staffView(day)}</div>`;
    return `${heading}${closed}${summary}<div class="grid drift-grid">${role === 'tilsett' ? taskPanel + otherPanels : otherPanels + taskPanel}</div>`;
  },
  menuView(day) {
    const menu = this.data.menus[day.id] || {};
    const allergens = ids => (ids || []).map(id => ALLERGENS[id] || id).join(', ');
    return `<section class="card"><div class="card-head"><div><p class="eyebrow">På tallerkenen</p><h2>Dagens meny</h2></div>${manager ? button('edit-menu', 'Endre meny') : ''}</div><p class="menu-text">${esc(menu.dagens || 'Meny er ikkje lagd inn')}</p>${menu.allergens?.length ? `<p class="allergens"><strong>Allergen og matomsyn:</strong> ${esc(allergens(menu.allergens))}</p>` : ''}${menu.alternativ ? `<div class="menu-alternative"><h3>Alternativ rett</h3><p>${esc(menu.alternativ)}</p>${menu.alternativ_allergenfri ? '<p class="allergens">Merkt allergenfri av kjøkkenet</p>' : menu.alternativ_allergens?.length ? `<p class="allergens"><strong>Allergen og matomsyn:</strong> ${esc(allergens(menu.alternativ_allergens))}</p>` : ''}</div>` : ''}</section>`;
  },
  tasksView(day) {
    const total = day.taskRows.length;
    const done = day.taskRows.filter(t => t.done).length;
    return `<section class="card"><div class="card-head"><div><p class="eyebrow">Steg for steg</p><h2>Sjekklister</h2></div>${teacher ? button('edit-tasks', 'Tilpass dagen') : ''}</div><div class="row spread"><small>${done} av ${total} ferdige</small>${badge(day.tasksCustom ? 'Tilpassa denne dagen' : 'Faste rutinar')}</div><progress class="progress" value="${done}" max="${Math.max(total, 1)}" aria-label="Utførte oppgåver"></progress>${role === 'tilsett' ? '<p id="time-gate" class="muted"></p>' : ''}${Object.entries(SHIFTS).map(([shift, label]) => `<h3 class="subheading">${label} <small>11.00–12.00</small></h3>${day.taskRows.filter(t => t.shift === shift).map(t => `<label class="check-row ${t.done ? 'done' : ''}"><input type="checkbox" data-action="check" data-key="${esc(t.id)}" ${t.done ? 'checked' : ''} ${canCheck(day, role, this.now()) ? '' : 'disabled'}><span>${esc(t.label)}</span></label>`).join('') || '<p class="muted">Ingen oppgåver for denne vakta.</p>'}`).join('')}${teacher && day.tasksCustom ? `<div class="staff-footer">${button('reset-tasks', 'Bruk faste rutinar igjen', '', 'quiet')}</div>` : ''}</section>`;
  },
  staffView(day) {
    const attendance = this.data.attendance[day.id] || {};
    const person = (name, substitute, shift) => {
      const present = attendance[name] !== false;
      return `<div class="staff-person ${present ? '' : 'absent'} ${substitute ? 'substitute' : ''}"><span>${esc(name)}${substitute ? '<small> · Vikar</small>' : ''}</span><div class="row">${manager ? button('attendance', present ? 'Til stades' : 'Fråvær', `data-name="${esc(name)}" data-present="${present}" aria-label="${present ? 'Registrer fråvær for' : 'Marker til stades:'} ${esc(name)}"`) : badge(present ? 'Til stades' : 'Fråvær', present ? '' : 'absent')}${substitute && manager ? button('remove-sub', 'Fjern', `data-name="${esc(name)}" data-shift="${shift}" aria-label="Fjern vikar ${esc(name)}"`, 'quiet') : ''}</div></div>`;
    };
    return `<section class="card"><div class="card-head"><div><p class="eyebrow">På jobb saman</p><h2>Bemanning</h2></div>${teacher ? button('edit-staff', 'Endre bemanning') : ''}</div>${badge(day.staffingCustom ? 'Tilpassa denne dagen' : `Grunnturnus ${day.turnusType}`)}${Object.entries(SHIFTS).map(([shift, label]) => { const subShift = shift === 'early' ? 'tidleg' : 'seint'; return `<h3 class="subheading">${label} <small>11.00–12.00</small></h3>${day.staffing[shift].map(name => person(name, false, subShift)).join('') || '<p class="muted">Ingen fast bemanning.</p>'}${day.substitutes[subShift].map(name => person(name, true, subShift)).join('')}${manager ? `<div class="staff-footer">${button('add-sub', '+ Set inn vikar', `data-shift="${subShift}"`, 'quiet')}</div>` : ''}`; }).join('')}${teacher && day.staffingCustom ? `<div class="staff-footer">${button('reset-staff', 'Bruk grunnturnus igjen', '', 'quiet')}</div>` : ''}</section>`;
  },
  planView() {
    return `<div class="page-heading"><div><p class="eyebrow">Veke for veke</p><h1>${teacher ? 'Planlegging' : 'Planoversikt'}</h1><p>Opne ein dag for å sjå meny, bemanning og oppgåver.</p></div>${teacher ? button('create-week', '+ Opprett veke', '', 'primary') : ''}</div><div class="toolbar">${field('search', 'Søk i planen', this.query, 'search', 'id="plan-search" placeholder="Namn, meny eller dato"')}</div><div id="plan-weeks"></div>`;
  },
  renderPlanWeeks() {
    const container = document.querySelector('#plan-weeks'); if (!container) return;
    const query = this.query.toLocaleLowerCase('nn-NO');
    const days = this.data.kalender.filter(day => !query || [day.id, displayDate(day.id, { weekday: 'long', year: 'numeric' }), this.data.menus[day.id]?.dagens, this.data.menus[day.id]?.alternativ, day.staffing.leader, ...day.staffing.early, ...day.staffing.late, ...day.substitutes.tidleg, ...day.substitutes.seint].join(' ').toLocaleLowerCase('nn-NO').includes(query));
    container.innerHTML = groupWeeks(days).map(([monday, week]) => `<section class="card week"><div class="week-head"><div><h2>Veke ${week[0].veke} <small>· ${esc(displayDate(monday, { year: 'numeric' }))}</small></h2><small>Turnus ${esc(week[0].turnusType)}</small></div>${teacher ? button('delete-week', 'Fjern veke', `data-monday="${monday}"`, 'quiet danger') : ''}</div><div class="day-grid">${week.map(day => button('date', `<strong>${esc(displayDate(day.id, { weekday: 'short', month: 'numeric' }))}</strong><span>${esc(this.data.menus[day.id]?.dagens || 'Meny ikkje sett')}</span><small>${esc(day.staffing.leader || 'Driftsleiar ikkje sett')}</small>${badge(day.status === 'open' ? 'Open' : 'Stengd', day.status === 'closed' ? 'closed' : '')}`, `data-date="${day.id}"`, `day-card ${day.id === this.data.today ? 'today' : ''}`)).join('')}</div></section>`).join('') || '<div class="card empty"><h2>Ingen dagar å vise</h2><p class="muted">Prøv eit anna søk, eller opprett ei ny veke.</p></div>';
  },
  setupView() {
    return `<div class="page-heading"><div><p class="eyebrow">Gode rutinar</p><h1>Oppsett</h1><p>Bygg grunnplanen her. Tilpass enkeltdagar under Dagens drift.</p></div></div><div class="setup-links">${button('edit-template', '<strong>Felles rutinar og vekedagsoppgåver</strong><small>Oppgåver for begge vakter, med eigne rutinar for kvar vekedag. Endringar gjeld frå neste dag.</small>')}${button('edit-staff-template', '<strong>Turnus og faste driftsleiarar</strong><small>Bemanning i A-, B- og C-veker og driftsleiar for kvar vekedag. Endringar gjeld framtidige dagar som følgjer grunnturnusen.</small>')}</div><div class="notice" style="margin-top:1.5rem"><strong>Arbeidstid og avkryssing</strong><br>Begge vakter er opne frå 11.00 til 12.00. Tilsette kan krysse av på dagen det gjeld; læraren kan korrigere seinare.</div>`;
  },
  updateTimeGate() {
    if (!this.data || this.tab !== 'today') return;
    const day = this.day(); if (!day) return;
    const allowed = canCheck(day, role, this.now()) && !this.busy && !this.pending;
    root.querySelectorAll('[data-action="check"]').forEach(el => { el.disabled = !allowed; });
    const message = document.querySelector('#time-gate');
    if (message) message.textContent = allowed ? 'De kan krysse av no. Avkryssinga stengjer klokka 12.00.' : 'Avkryssing er open klokka 11.00–12.00 på ein open arbeidsdag.';
  },
  openEditor(kind, fields = {}, rows = null, title = '') {
    this.edit = { kind, fields: clone(fields), rows: rows ? clone(rows) : null, revision: this.data.revision, dateId: this.date, dirty: false, title };
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
      group.querySelector('input').setCustomValidity(group.querySelector('input:checked') ? '' : 'Vel minst ein vekedag for oppgåva.');
    });
  },
  renderEditor() {
    const edit = this.edit; const f = edit.fields;
    let content = ''; let title = edit.title;
    if (edit.kind === 'menu') {
      title = 'Endre dagens meny';
      const allergenFields = (name, selected) => `<div class="choices">${Object.entries(ALLERGENS).map(([id, label]) => `<label><input type="checkbox" name="${name}" value="${id}" ${(selected || []).includes(id) ? 'checked' : ''}>${label}</label>`).join('')}</div>`;
      content = `${textarea('value', 'Hovudrett', f.value, 'maxlength="2000"')}<fieldset><legend>Allergen og matomsyn</legend>${allergenFields('allergens', f.allergens)}</fieldset>${textarea('alternative', 'Alternativ rett', f.alternative, 'maxlength="2000"')}<fieldset><legend>Allergen og matomsyn for alternativet</legend><label class="check-row"><input name="alternativeFree" type="checkbox" ${f.alternativeFree ? 'checked' : ''}><span>Alternativet er allergenfritt</span></label>${allergenFields('alternativeAllergens', f.alternativeAllergens)}</fieldset>`;
    } else if (edit.kind === 'staff') {
      title = 'Bemanning for denne dagen';
      content = `<p class="muted">Endringa gjeld berre ${esc(displayDate(edit.dateId))}. Skriv eitt namn per linje, eller skil med komma. Eit tomt felt gir ei tom vakt.</p>${field('leader', 'Driftsleiar', f.leader, 'text', 'maxlength="160"')}<div class="form-grid">${textarea('early', 'Tidlegvakt', f.early)}${textarea('late', 'Seinvakt', f.late)}</div>`;
    } else if (edit.kind === 'tasks' || edit.kind === 'template') {
      title = edit.kind === 'template' ? 'Felles rutinar og vekedagsoppgåver' : 'Sjekkliste for denne dagen';
      content = `<p class="muted">${edit.kind === 'template' ? `Endringar gjeld frå ${esc(displayDate(this.data.templateEffective))}. Dagens liste, historikken og særskilt tilpassa dagar blir bevarte.` : 'Endre oppgåvene for denne datoen. Avkryssingar blir bevarte når du flyttar oppgåver.'}</p><div class="stack">${edit.rows.map((row, i) => `<div class="task-editor-row" data-row-id="${esc(row.id)}"><label>Oppgåve<input data-col="label" value="${esc(row.label)}" required maxlength="255"></label><label>Vakt<select data-col="shift">${Object.entries(SHIFTS).map(([id, label]) => `<option value="${id}" ${row.shift === id ? 'selected' : ''}>${label}</option>`).join('')}</select></label>${edit.kind === 'template' ? `<fieldset class="weekday-field"><legend>Når skal oppgåva gjerast?</legend><div class="choices">${WEEKDAYS.map((label, n) => `<label><input type="checkbox" data-col="weekday" value="${n + 1}" ${taskDays(row).includes(n + 1) ? 'checked' : ''}>${label}</label>`).join('')}</div></fieldset>` : ''}<div class="row">${button('move-task', '↑ Opp', `data-index="${i}" data-step="-1" aria-label="Flytt oppgåve ${i + 1} opp" ${i === 0 ? 'disabled' : ''}`)}${button('move-task', '↓ Ned', `data-index="${i}" data-step="1" aria-label="Flytt oppgåve ${i + 1} ned" ${i === edit.rows.length - 1 ? 'disabled' : ''}`)}${button('remove-task', 'Fjern', `data-index="${i}" aria-label="Fjern oppgåve ${i + 1}"`, 'danger')}</div></div>`).join('')}</div>${button('add-task', '+ Legg til oppgåve')}`;
    } else if (edit.kind === 'calendar') {
      title = 'Endre arbeidsdag';
      content = `<label>Status<select name="status"><option value="open" ${f.status === 'open' ? 'selected' : ''}>Open kantine</option><option value="closed" ${f.status === 'closed' ? 'selected' : ''}>Stengd kantine</option></select></label>${textarea('note', 'Merknad', f.note, 'maxlength="255"')}<p class="muted">Når dagen er stengd, blir merknaden også vist på den offentlege infoskjermen. Bruk til dømes «Ferie» eller «Planleggingsdag».</p>`;
    } else if (edit.kind === 'week') {
      title = 'Opprett ei veke';
      content = `<p class="muted">Vel måndagen. Vekenummeret blir rekna ut automatisk, og fem arbeidsdagar blir oppretta.</p>${field('monday', 'Dato for måndag', f.monday, 'date', 'required')}<label>Turnus<select name="turnus">${['A', 'B', 'C', 'Ferie'].map(t => `<option value="${t}" ${f.turnus === t ? 'selected' : ''}>${t === 'Ferie' ? 'Ferie – alle dagar stengde' : `Veke ${t}`}</option>`).join('')}</select></label>`;
    } else if (edit.kind === 'sub') {
      title = `Set inn vikar · ${f.shift === 'tidleg' ? 'tidlegvakt' : 'seinvakt'}`;
      content = `${field('name', 'Namn på vikar', f.name, 'text', 'required maxlength="160" list="known-names"')}<input type="hidden" name="shift" value="${esc(f.shift)}"><datalist id="known-names">${this.knownNames().map(name => `<option value="${esc(name)}">`).join('')}</datalist>`;
    } else if (edit.kind === 'staff-template') {
      title = 'Turnus og faste driftsleiarar';
      content = '<p class="muted">Endringar gjeld framtidige dagar som følgjer grunnturnusen. Dagens bemanning, historikken og tilpassa dagar blir bevarte. Skil namna med komma.</p><fieldset><legend>Driftsleiarar</legend><div class="form-grid">';
      content += WEEKDAYS.map(day => field(`driftsleiar_${day}`, day, f[`driftsleiar_${day}`], 'text', 'maxlength="160"')).join('') + '</div></fieldset>';
      content += ['A', 'B', 'C'].map(turnus => `<fieldset><legend>Turnus ${turnus}</legend>${WEEKDAYS.map(day => `<h3 class="subheading">${day}</h3><div class="form-grid">${field(`turnus_${turnus}_${day}_tidleg`, 'Tidlegvakt', f[`turnus_${turnus}_${day}_tidleg`])}${field(`turnus_${turnus}_${day}_seint`, 'Seinvakt', f[`turnus_${turnus}_${day}_seint`])}</div>`).join('')}</fieldset>`).join('');
    } else if (edit.kind === 'confirm') content = `<p class="confirm-text">${esc(f.message)}</p>`;
    dialog.innerHTML = `<form id="edit-form"><header class="dialog-head"><h2 id="editor-title">${esc(title)}</h2>${button('close-editor', 'Lukk', 'aria-label="Lukk dialog"', 'quiet')}</header><div class="dialog-body">${content}<div id="editor-error" class="notice error" role="alert"></div><div id="conflict-review"></div></div><footer class="dialog-actions">${button('close-editor', 'Avbryt')}<button type="submit" class="primary">${edit.kind === 'confirm' ? 'Stadfest' : 'Lagre'}</button></footer></form>`;
    this.validateTaskDays();
  },
  knownNames() {
    return [...new Set(this.data.kalender.flatMap(day => [...day.staffing.early, ...day.staffing.late, ...day.substitutes.tidleg, ...day.substitutes.seint]))].sort((a, b) => a.localeCompare(b, 'nn'));
  },
  closeEditor() {
    if (this.busy) return;
    if ((this.edit?.dirty || this.pending) && !window.confirm('Forkaste det ulagrede utkastet?')) return;
    this.edit = null; this.pending = null; dialog.close(); dialog.replaceChildren(); this.refresh();
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
    this.pending = payload; this.busy = true; this.status('Lagrar …');
    dialog.querySelectorAll('button,input,textarea,select').forEach(el => { el.disabled = true; });
    this.updateTimeGate();
    try {
      const data = await this.request({ action: 'save', ...payload });
      this.accept(data); this.pending = null; this.edit = null; dialog.close(); dialog.replaceChildren(); this.render(); this.status('Lagra');
    } catch (error) {
      if (this.authenticated) {
        if (!this.edit) { this.openEditor('confirm', { message: 'Handlinga er ikkje stadfesta. Du kan prøve igjen eller avbryte.' }, null, 'Lagringa stoppa'); this.edit.payload = payload; }
        const errorEl = document.querySelector('#editor-error'); if (errorEl) errorEl.textContent = error.message;
        if (error.status === 409) document.querySelector('#conflict-review').innerHTML = button('review-conflict', 'Hent siste versjon for samanlikning');
      }
      this.status('Ikkje lagra');
    } finally {
      this.busy = false;
      // Rerendering would erase drafts; only release controls we disabled.
      dialog.querySelectorAll('button,input,textarea,select').forEach(el => { el.disabled = false; });
      this.updateTimeGate();
    }
  },
  describe(data, payload) {
    const day = data.kalender.find(d => d.id === payload.dateId);
    if (payload.type === 'menu_day') { const m = data.menus[payload.dateId] || {}; return `Hovudrett: ${m.dagens || '—'}\nAllergen: ${(m.allergens || []).map(id => ALLERGENS[id]).join(', ')}\nAlternativ: ${m.alternativ || '—'}\nAllergen alternativ: ${m.alternativ_allergenfri ? 'Merkt allergenfri' : (m.alternativ_allergens || []).map(id => ALLERGENS[id]).join(', ')}`; }
    if (payload.type === 'task_templates' || payload.type === 'day_tasks' || payload.type === 'task_check') return (payload.type === 'task_templates' ? data.templateRows : day?.taskRows || []).map(t => `${SHIFTS[t.shift]}${payload.type === 'task_templates' ? ` · ${taskDays(t).map(n => WEEKDAYS[n - 1]).join(', ')}` : ''}: ${t.label}${t.done ? ' (ferdig)' : ''}`).join('\n');
    if (payload.type === 'day_staffing') return day ? `Driftsleiar: ${day.staffing.leader}\nTidleg: ${day.staffing.early.join(', ')}\nSeint: ${day.staffing.late.join(', ')}` : 'Dagen er fjerna.';
    if (payload.type === 'calendar_day') return day ? `${day.status === 'open' ? 'Open' : 'Stengd'}\n${day.merknad}` : 'Dagen er fjerna.';
    if (payload.type === 'attendance') return `${payload.name}: ${data.attendance[payload.dateId]?.[payload.name] === false ? 'Fråvær' : 'Til stades'}`;
    if (payload.type.startsWith('vikar_')) return day ? `Vikarar: ${day.substitutes.tidleg.join(', ')} (tidleg); ${day.substitutes.seint.join(', ')} (seint)` : 'Dagen er fjerna.';
    if (payload.type === 'staff_templates') return Object.entries(data.bemanning.driftsleiarar).map(([d, n]) => `${d}: ${n}`).join('\n') + '\n' + ['A', 'B', 'C'].flatMap(t => WEEKDAYS.map(d => `${t} · ${d}: ${data.bemanning.turnus[t]?.[d]?.tidleg || '—'} / ${data.bemanning.turnus[t]?.[d]?.seint || '—'}`)).join('\n');
    return data.kalender.filter(d => d.weekStart === payload.monday).map(d => `${d.id}: ${d.status === 'open' ? 'Open' : 'Stengd'}, turnus ${d.turnusType}`).join('\n') || 'Veka finst ikkje.';
  },
  async reviewConflict() {
    try {
      this.capture();
      const latest = await this.request({ action: 'load' });
      if (!this.edit) return;
      this.edit.reviewRevision = latest.revision;
      document.querySelector('#conflict-review').innerHTML = `<h3>Siste lagra versjon</h3><div class="comparison">${esc(this.describe(latest, this.pending || this.payload()))}</div><p class="muted">Utkastet ditt står framleis i felta over. Samanlikn og juster det før du lagrar.</p>${button('save-reviewed', 'Lagre etter samanlikning', '', 'primary')}`;
    } catch (error) { const el = document.querySelector('#editor-error'); if (el) el.textContent = error.message; }
  },
  async submit(event) {
    event.preventDefault();
    if (event.target.id === 'login-form') {
      const btn = event.target.querySelector('button'); btn.disabled = true;
      try {
        const password = new FormData(event.target).get('password');
        this.accept(await this.request({ action: 'load', password })); this.authenticated = true; this.render();
        if (this.edit) { this.renderEditor(); dialog.showModal(); document.querySelector('#editor-error').textContent = 'Utkastet er bevart. Prøv å lagre igjen.'; }
        else if (this.pending) { const p = this.pending; this.openEditor('confirm', { message: 'Du har ei handling som ikkje er stadfesta.' }, null, 'Prøv lagring igjen'); this.edit.payload = p; dialog.showModal(); }
        this.schedule();
      } catch (error) { const el = document.querySelector('#login-error'); if (el) el.textContent = error.message; }
      finally { btn.disabled = false; }
    } else if (event.target.id === 'edit-form') await this.save(this.payload());
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
      case 'logout':
        if ((this.edit?.dirty || this.pending) && !confirm('Forkaste ulagrede endringar og logge ut?')) return;
        try { await this.request({ action: 'logout' }); this.edit = null; this.pending = null; this.data = null; this.authenticated = false; this.loginView(); }
        catch (error) { this.notice('Utlogginga vart ikkje stadfesta. Prøv igjen.'); } break;
      case 'edit-menu': { const m = this.data.menus[this.date] || {}; this.openEditor('menu', { value: m.dagens || '', alternative: m.alternativ || '', allergens: m.allergens || [], alternativeAllergens: m.alternativ_allergens || [], alternativeFree: Boolean(m.alternativ_allergenfri) }); break; }
      case 'edit-staff': this.openEditor('staff', { leader: day.staffing.leader, early: day.staffing.early.join('\n'), late: day.staffing.late.join('\n') }); break;
      case 'edit-tasks': this.openEditor('tasks', {}, day.taskRows); break;
      case 'edit-template': this.openEditor('template', {}, this.data.templateRows); break;
      case 'edit-calendar': this.openEditor('calendar', { status: day.status, note: day.merknad }); break;
      case 'create-week': this.openEditor('week', { monday: '', turnus: 'A' }); break;
      case 'add-sub': this.openEditor('sub', { name: '', shift: target.dataset.shift }); break;
      case 'attendance': this.quick({ type: 'attendance', name: target.dataset.name, value: target.dataset.present !== 'true' }); break;
      case 'remove-sub': this.quick({ type: 'vikar_remove', name: target.dataset.name, shift: target.dataset.shift }); break;
      case 'reset-staff': this.confirm('Bruk grunnturnus igjen', 'Erstatte dagsbemanninga med grunnturnusen? Vikarane blir bevarte.', { type: 'day_staffing', reset: true }); break;
      case 'reset-tasks': this.confirm('Bruk faste rutinar igjen', 'Erstatte dagslista med rutinane som gjeld for datoen? Ekstra dagsoppgåver blir tekne ut av lista. Avkryssingar for oppgåver som framleis finst, blir bevarte.', { type: 'day_tasks', reset: true }); break;
      case 'delete-week': this.confirm('Fjern veka frå planen', `Fjerne veka som startar ${displayDate(target.dataset.monday, { year: 'numeric' })}? Menyar og historiske registreringar blir bevarte dersom du opprettar veka igjen.`, { type: 'delete_week', monday: target.dataset.monday }); break;
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
