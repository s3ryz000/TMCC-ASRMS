export const formatTime = (date) => {
    if (!date) return '';
    const d = new Date(date);
    return d.toLocaleString('en-PH', {
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  };

// The API returns timestamps in UTC (e.g. 2026-10-01T02:00:00.000000Z); show them in
// the registrar office's local time so a 10:00 AM timestamp doesn't read as 02:00.
export const formatDateTime = (value) => {
  if (!value) return '—';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return String(value);
  return d.toLocaleString('en-PH', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
};
// ── Calendar dates (#77) ───────────────────────────────────────────────────
// Date-only values (birth, enrollment) must never go through
// toISOString(): that converts to UTC, which in Manila (UTC+8) is the previous
// day for any time before 8 AM.

/** "YYYY-MM-DD" for a local Date (default: today here), for date inputs and "today". */
export const localDateString = (date = new Date()) => {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
};

/** An API date ("2006-06-21") as the value of an <input type="date">; '' when empty. */
export const toDateInputValue = (value) => {
  if (!value) return '';
  const match = String(value).match(/^(\d{4}-\d{2}-\d{2})/);
  return match ? match[1] : '';
};

/** Display an API date ("2006-06-21") as "June 21, 2006" without any timezone shift. */
export const formatDateOnly = (value, options = { year: 'numeric', month: 'long', day: 'numeric' }) => {
  const ymd = toDateInputValue(value);
  if (!ymd) return value ? String(value) : '—';
  const [y, m, d] = ymd.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString('en-PH', options);
};
