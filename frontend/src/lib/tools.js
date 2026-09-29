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
// the registrar office's local time so a 10:00 AM appointment doesn't read as 02:00.
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