import test from 'node:test';
import assert from 'node:assert/strict';
import { createDemoStore, STORAGE_KEY, DEMO_TODAY } from '../kantine/assets/demo-data.js';
const storage = () => {
  const values = new Map([['unrelated-school-tool', 'keep me']]);
  return { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), values };
};
const load = (store, role = 'laerar') => store.request(role, { action: 'load' });
const save = (store, type, fields = {}, role = 'laerar') => store.request(role, { action: 'save', type, revision: load(store, role).revision, dateId: DEMO_TODAY, ...fields });
const today = data => data.kalender.find(d => d.id === DEMO_TODAY);

test('three fictional weeks, fixed demonstration time, role limits and closed-day gate', () => {
  const store = createDemoStore(() => null);
  const data = load(store);
  assert.equal(data.kalender.length, 15);
  assert.equal(data.serverTime, '2026-10-21T11:30:00+02:00');
  assert.equal(data.today, DEMO_TODAY);
  assert.throws(() => save(store, 'menu_day', {}, 'tilsett'), e => e.status === 403);
  assert.throws(() => save(store, 'day_staffing', {}, 'driftsleiar'), e => e.status === 403);
  save(store, 'task_check', { key: 'prepare', value: true }, 'tilsett');
  assert.equal(today(load(store)).taskRows.find(t => t.id === 'prepare').done, true);
  save(store, 'calendar_day', { status: 'closed', note: 'Planning day' });
  assert.throws(() => save(store, 'task_check', { key: 'clean', value: true }, 'tilsett'), e => e.status === 403);
  save(store, 'task_check', { key: 'clean', value: true });
});

test('manager edits persist across stores and roles; reset touches only the demo namespace', () => {
  const disk = storage(), first = createDemoStore(() => disk);
  save(first, 'menu_day', { value: 'Demo soup', alternative: '', allergens: ['gluten'], alternativeAllergens: [], alternativeFree: false }, 'driftsleiar');
  save(first, 'attendance', { name: 'Student 1', value: false }, 'driftsleiar');
  save(first, 'vikar_add', { name: 'Student Z', shift: 'tidleg' }, 'driftsleiar');
  const second = createDemoStore(() => disk);
  assert.equal(load(second, 'tilsett').menus[DEMO_TODAY].dagens, 'Demo soup');
  assert.equal(load(second).attendance[DEMO_TODAY]['Student 1'], false);
  assert.deepEqual(today(load(second)).substitutes.tidleg, ['Student Z']);
  save(second, 'vikar_remove', { name: 'Student Z', shift: 'tidleg' }, 'driftsleiar');
  assert.deepEqual(today(load(second)).substitutes.tidleg, []);
  const oldRevision = load(second).revision;
  const reset = second.reset();
  assert.ok(reset.revision > oldRevision);
  assert.equal(reset.kalender.length, 15);
  assert.equal(reset.menus[DEMO_TODAY].dagens, 'Wholemeal rolls and fresh fruit');
  assert.equal(disk.values.get('unrelated-school-tool'), 'keep me');
  assert.ok(disk.values.has(STORAGE_KEY));
});

test('revision conflict keeps stored state intact and validation is atomic', () => {
  const disk = storage(), a = createDemoStore(() => disk), b = createDemoStore(() => disk);
  const stale = load(b).revision;
  save(a, 'attendance', { name: 'Student 2', value: false });
  assert.throws(() => b.request('laerar', { action: 'save', type: 'attendance', dateId: DEMO_TODAY, name: 'Student 2', value: true, revision: stale }), e => e.status === 409);
  assert.equal(load(b).attendance[DEMO_TODAY]['Student 2'], false);
  const before = load(a);
  assert.throws(() => save(a, 'menu_day', { value: 'Invalid', allergens: ['not-an-allergen'], alternativeAllergens: [] }));
  assert.deepEqual(load(a), before);
});

test('daily tasks retain checks by stable ID and templates affect future inherited days only', () => {
  const store = createDemoStore(() => null);
  const original = today(load(store)).taskRows;
  save(store, 'day_tasks', { rows: original.filter(t => t.id !== 'wash').reverse() });
  save(store, 'day_tasks', { rows: original });
  assert.equal(today(load(store)).taskRows.find(t => t.id === 'wash').done, true);
  const rows = [{ id: 'new', label: 'New weekday task', shift: 'early', weekdays: [4] }];
  save(store, 'day_tasks', { dateId: '2026-10-23', rows: [{ id: 'custom', label: 'Day-specific work', shift: 'late' }] });
  save(store, 'task_templates', { rows });
  const after = load(store);
  assert.equal(today(after).taskRows.length, 6);
  assert.equal(after.kalender.find(d => d.id === '2026-10-22').taskRows[0].id, 'new');
  assert.equal(after.kalender.find(d => d.id === '2026-10-23').taskRows[0].id, 'custom');
  save(store, 'day_tasks', { dateId: '2026-10-23', reset: true });
  assert.equal(load(store).kalender.find(d => d.id === '2026-10-23').taskRows.length, 0);
});

test('empty staffing, recurring staffing snapshots and archived week recreation', () => {
  const store = createDemoStore(() => null);
  save(store, 'day_staffing', { leader: '', early: [], late: [] });
  assert.deepEqual(today(load(store)).staffing, { leader: '', early: [], late: [] });
  save(store, 'day_staffing', { reset: true });
  const before = today(load(store)).staffing;
  const staffing = load(store).bemanning;
  const items = Object.keys(staffing.driftsleiarar).flatMap(day => [
    { key: `driftsleiar_${day}`, value: 'Student X' },
    ...['A','B','C'].flatMap(t => [{ key: `turnus_${t}_${day}_tidleg`, value: 'Student Y' }, { key: `turnus_${t}_${day}_seint`, value: '' }]),
  ]);
  save(store, 'staff_templates', { items });
  assert.deepEqual(today(load(store)).staffing, before);
  assert.deepEqual(load(store).kalender.find(d => d.id === '2026-10-22').staffing.early, ['Student Y']);
  save(store, 'delete_week', { monday: '2026-10-19' });
  assert.equal(load(store).kalender.length, 10);
  save(store, 'create_week', { monday: '2026-10-19', turnus: 'A' });
  assert.equal(load(store).kalender.length, 15);
  assert.deepEqual(today(load(store)).staffing, before);
  assert.equal(today(load(store)).taskRows.find(t => t.id === 'wash').done, true);
  save(store, 'create_week', { monday: '2026-11-09', turnus: 'Holiday' });
  assert.equal(load(store).kalender.at(-1).status, 'closed');
  assert.throws(() => save(store, 'create_week', { monday: '2026-11-10', turnus: 'A' }));
});

test('blocked storage and corrupt saves recover without losing in-page role changes', () => {
  for (const getStorage of [() => { throw new Error('Blocked'); }, () => ({ getItem() { return null; }, setItem() { throw new Error('Quota'); } })]) {
    const store = createDemoStore(getStorage);
    save(store, 'attendance', { name: 'Student 1', value: false }, 'driftsleiar');
    assert.equal(load(store, 'tilsett').attendance[DEMO_TODAY]['Student 1'], false);
    assert.match(store.storageMessage, /unavailable/);
    store.reset();
    assert.deepEqual(load(store).attendance, {});
  }
  const disk = storage(); disk.setItem(STORAGE_KEY, '{broken');
  const store = createDemoStore(() => disk);
  assert.equal(load(store).kalender.length, 15);
  assert.match(store.storageMessage, /restored/);
});
