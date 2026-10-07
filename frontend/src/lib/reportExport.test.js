import { buildCsvRows, buildPrintHtml, formatRate, rangeLabel, recordTypeLabel, toCsv } from './reportExport';

const input = {
  range: { date_from: '2026-09-01', date_to: '2026-09-30' },
  requests: {
    total: 5,
    by_status: { pending: 1, approved: 1, rejected: 1, released: 2 },
    by_record_type: [{ record_type: 'certificate_of_grades', total: 1 }],
    approval_rate: 75,
    avg_processing_time_days: 1.5,
  },
  activity: {
    total: 2,
    by_day: [{ date: '2026-09-01', total: 2 }],
    by_role: [{ role: 'staff', total: 2 }],
    top_actions: [{ action: 'Request <approved>', total: 2 }],
  },
  exportRows: [{ student_number: '2026-00001', student_name: 'Dela Cruz, "Juan"', record_type: 'transcript', status: 'released' }],
};

describe('Report export (#96)', () => {
  test('labels', () => {
    expect(recordTypeLabel('certificate_of_grades')).toBe('Certificate of grades');
    expect(formatRate(75)).toBe('75%');
    expect(formatRate(null)).toBe('—');
    expect(rangeLabel(input.range)).toBe('2026-09-01 to 2026-09-30');
    expect(rangeLabel({})).toBe('All dates');
  });

  test('the CSV carries the range, the figures and the request list', () => {
    const csv = toCsv(buildCsvRows(input));
    expect(csv).toContain('ASRMS report,2026-09-01 to 2026-09-30');
    expect(csv).toContain('Released,2');
    expect(csv).toContain('Approval rate,75%');
    expect(csv).toContain('Certificate of grades,1');
    expect(csv).toContain('"Dela Cruz, ""Juan"""');
  });

  test('the printable page escapes record data', () => {
    const html = buildPrintHtml({ ...input, generatedAt: new Date(2026, 9, 7) });
    expect(html).toContain('Request &lt;approved&gt;');
    expect(html).not.toContain('Request <approved>');
    expect(html).toContain('2026-09-01 to 2026-09-30');
  });
});
