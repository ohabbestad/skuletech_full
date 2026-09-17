import test from 'node:test';
import assert from 'node:assert/strict';
import { groupWeeks, canCheck, names, esc, osloClock } from '../assets/model.js';
test('week identity includes year and follows Monday', () => {
  const groups = groupWeeks([{weekStart:'2026-12-28',veke:53},{weekStart:'2025-12-29',veke:1},{weekStart:'2026-12-28',veke:53}]);
  assert.equal(groups.length,2); assert.equal(groups[1][1].length,2);
});
test('shared employee gate, Oslo time and role boundaries', () => {
  const day = {id:'2026-09-16',status:'open'};
  for (const [time, expected] of [['08:59:59',false],['09:00:00',true],['09:59:59',true],['10:00:00',false]]) assert.equal(canCheck(day,'tilsett',new Date(`2026-09-16T${time}Z`)),expected);
  assert.equal(canCheck(day,'driftsleiar',new Date('2026-09-16T09:30:00Z')),false);
  assert.equal(canCheck({...day,status:'closed'},'tilsett',new Date('2026-09-16T09:30:00Z')),false);
  assert.equal(canCheck(day,'laerar',new Date('2027-01-01T00:00:00Z')),true);
  assert.equal(osloClock(new Date('2026-01-16T10:30:00Z')).minutes,690);
});
test('text and names remain data', () => {
  assert.deepEqual(names('Elev A, Elev B\nElev A'),['Elev A','Elev B']);
  assert.equal(esc(`<img onerror="x"> & 'ø'`),'&lt;img onerror=&quot;x&quot;&gt; &amp; &#39;ø&#39;');
});
