export const WEEKDAYS = ['Måndag', 'Tysdag', 'Onsdag', 'Torsdag', 'Fredag'];
export const SHIFTS = { early: 'Tidlegvakt', late: 'Seinvakt' };
export const ALLERGENS = { gluten: 'Gluten', skalldyr: 'Skalldyr', egg: 'Egg', fisk: 'Fisk', peanotter: 'Peanøtter', soya: 'Soya', mjolk: 'Mjølk', laktose: 'Laktose', notter: 'Nøtter', selleri: 'Selleri', sennep: 'Sennep', sesam: 'Sesam', sulfitt: 'Sulfitt', lupin: 'Lupin', blautdyr: 'Blautdyr' };
export const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
export const names = value => [...new Set(String(value).split(/[,\n]/).map(name => name.trim()).filter(Boolean))];
export const displayDate = (date, options = {}) => new Intl.DateTimeFormat('nn-NO', { day: 'numeric', month: 'long', ...options }).format(new Date(`${date}T12:00:00`));
export function osloClock(now = new Date()) {
  const parts = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Oslo', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(now).map(p => [p.type, p.value]));
  return { date: `${parts.year}-${parts.month}-${parts.day}`, minutes: Number(parts.hour) * 60 + Number(parts.minute) };
}
export function canCheck(day, role, now) {
  if (role === 'laerar') return true;
  const clock = osloClock(now);
  return role === 'tilsett' && day.status === 'open' && day.id === clock.date && clock.minutes >= 660 && clock.minutes < 720;
}
export function groupWeeks(days) {
  const groups = new Map();
  for (const day of days) {
    if (!groups.has(day.weekStart)) groups.set(day.weekStart, []);
    groups.get(day.weekStart).push(day);
  }
  return [...groups.entries()].sort(([a], [b]) => a.localeCompare(b));
}
export function uid() {
  return `task_${crypto.randomUUID().replaceAll('-', '')}`;
}
