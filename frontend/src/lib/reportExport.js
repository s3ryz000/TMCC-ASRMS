/**
 * Admin report export (#96): the CSV rows and the printable page (saved as
 * PDF from the browser) for one date range. Both are built from the same
 * figures the Reports page shows.
 */

export const STATUS_LABELS = {
  pending: 'Pending',
  approved: 'Approved',
  rejected: 'Rejected',
  released: 'Released',
};

/** "certificate_of_grades" → "Certificate of grades". */
export const recordTypeLabel = (type) => {
  if (!type) return '—';
  const words = String(type).replace(/_/g, ' ');
  return words.charAt(0).toUpperCase() + words.slice(1);
};

export const roleLabel = (role) => {
  if (!role) return '—';
  return role.charAt(0).toUpperCase() + role.slice(1);
};

/** 75 → "75%", null → "—" (nothing decided yet). */
export const formatRate = (rate) => (rate == null ? '—' : `${rate}%`);

/** 1.5 → "1.5 days", null → "—" (nothing processed yet). */
export const formatDays = (days) => {
  if (days == null) return '—';
  return `${days} ${Number(days) === 1 ? 'day' : 'days'}`;
};

export const rangeLabel = ({ date_from: from, date_to: to } = {}) => {
  if (from && to) return `${from} to ${to}`;
  if (from) return `From ${from}`;
  if (to) return `Up to ${to}`;
  return 'All dates';
};

/**
 * Rows for the CSV file: the summary figures, then every request in the
 * range. Each row is an array of cells; [] is a blank line.
 */
export function buildCsvRows({ range, requests, activity, exportRows }) {
  const rows = [
    ['ASRMS report', rangeLabel(range)],
    [],
    ['REQUESTS'],
    ['Total requests', requests.total],
    ...Object.keys(STATUS_LABELS).map((s) => [STATUS_LABELS[s], requests.by_status?.[s] ?? 0]),
    ['Approval rate', formatRate(requests.approval_rate)],
    ['Average processing time', formatDays(requests.avg_processing_time_days)],
    [],
    ['Record type', 'Requests'],
    ...(requests.by_record_type || []).map((r) => [recordTypeLabel(r.record_type), r.total]),
    [],
    ['ACTIVITY'],
    ['Total log entries', activity.total],
    [],
    ['Role', 'Entries'],
    ...(activity.by_role || []).map((r) => [roleLabel(r.role), r.total]),
    [],
    ['Action', 'Times'],
    ...(activity.top_actions || []).map((r) => [r.action, r.total]),
    [],
    ['Date', 'Entries'],
    ...(activity.by_day || []).map((r) => [r.date, r.total]),
    [],
    ['REQUEST LIST'],
    ['Student Number', 'Student Name', 'Record Type', 'Purpose', 'Status', 'Date Requested', 'Date Processed', 'Date Released'],
    ...(exportRows || []).map((r) => [
      r.student_number,
      r.student_name,
      recordTypeLabel(r.record_type),
      r.purpose,
      STATUS_LABELS[r.status] || r.status,
      r.requested_at,
      r.processed_at,
      r.released_at,
    ]),
  ];
  return rows;
}

function escapeCsvCell(val) {
  const s = val == null ? '' : String(val);
  if (/[",\n\r]/.test(s)) return `"${s.replace(/"/g, '""')}"`;
  return s;
}

export function toCsv(rows) {
  return rows.map((row) => row.map(escapeCsvCell).join(',')).join('\r\n');
}

export function downloadCsv(filename, rows) {
  // The BOM lets Excel open the file as UTF-8 (names with ñ, etc.).
  const blob = new Blob(['﻿' + toCsv(rows)], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
}

const esc = (s) =>
  String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

const table = (headers, rows) => `
  <table>
    <thead><tr>${headers.map((h) => `<th>${esc(h)}</th>`).join('')}</tr></thead>
    <tbody>${
      rows.length === 0
        ? `<tr><td colspan="${headers.length}" class="empty">None in this period.</td></tr>`
        : rows.map((r) => `<tr>${r.map((c) => `<td>${esc(c)}</td>`).join('')}</tr>`).join('')
    }</tbody>
  </table>`;

/** The printable page; the browser's print dialog saves it as PDF. */
export function buildPrintHtml({ range, requests, activity, exportRows, generatedAt = new Date() }) {
  return `<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8"/>
  <title>ASRMS Report - ${esc(rangeLabel(range))}</title>
  <style>
    body { font-family: Arial, sans-serif; padding: 32px; color: #111; font-size: 13px; }
    .header { display: flex; align-items: center; gap: 15px; border-bottom: 2px solid #000; padding-bottom: 10px; }
    .logo { width: 56px; height: 56px; object-fit: contain; }
    .title { font-size: 18px; font-weight: bold; }
    .subtitle, .meta { font-size: 12px; color: #555; }
    h2 { font-size: 15px; margin: 24px 0 8px; }
    .kpis { display: flex; gap: 24px; flex-wrap: wrap; margin-bottom: 8px; }
    .kpis div { min-width: 120px; }
    .kpis strong { display: block; font-size: 18px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
    th { background: #f0f0f0; }
    .empty { color: #777; font-style: italic; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  </style>
</head>
<body>
  <div class="header">
    <img src="/logo.png" class="logo" alt="" />
    <div>
      <div class="title">Requests and Activity Report</div>
      <div class="subtitle">Student Records Management System</div>
    </div>
  </div>
  <p class="meta">Period: <strong>${esc(rangeLabel(range))}</strong> · Generated on ${esc(generatedAt.toLocaleString())}</p>

  <h2>Record requests</h2>
  <div class="kpis">
    <div>Total requests<strong>${esc(requests.total)}</strong></div>
    <div>Approval rate<strong>${esc(formatRate(requests.approval_rate))}</strong></div>
    <div>Average processing time<strong>${esc(formatDays(requests.avg_processing_time_days))}</strong></div>
  </div>
  <div class="grid">
    ${table(['Status', 'Requests'], Object.keys(STATUS_LABELS).map((s) => [STATUS_LABELS[s], requests.by_status?.[s] ?? 0]))}
    ${table(['Record type', 'Requests'], (requests.by_record_type || []).map((r) => [recordTypeLabel(r.record_type), r.total]))}
  </div>

  <h2>System activity</h2>
  <div class="kpis"><div>Total log entries<strong>${esc(activity.total)}</strong></div></div>
  <div class="grid">
    ${table(['Role', 'Entries'], (activity.by_role || []).map((r) => [roleLabel(r.role), r.total]))}
    ${table(['Top actions', 'Times'], (activity.top_actions || []).map((r) => [r.action, r.total]))}
  </div>
  ${table(['Date', 'Entries'], (activity.by_day || []).map((r) => [r.date, r.total]))}

  <h2>Request list</h2>
  ${table(
    ['Student No.', 'Student Name', 'Record Type', 'Status', 'Requested', 'Processed', 'Released'],
    (exportRows || []).map((r) => [
      r.student_number,
      r.student_name,
      recordTypeLabel(r.record_type),
      STATUS_LABELS[r.status] || r.status,
      (r.requested_at || '').slice(0, 10),
      (r.processed_at || '').slice(0, 10),
      (r.released_at || '').slice(0, 10),
    ]),
  )}
</body>
</html>`;
}
