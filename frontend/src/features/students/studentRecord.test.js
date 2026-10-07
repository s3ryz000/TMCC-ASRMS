import { awardsFrom, formatGwa, roadmapMatchesStatus, subjectStatusBadge } from './studentRecord';

describe('student record helpers (#57)', () => {
  test('awards are exactly the ones the backend marks eligible', () => {
    const summary = {
      overall_gwa: 1.45,
      latin_honors: { eligible: true, honor: 'Magna Cum Laude', reason: null },
      years: [
        { academic_year: '2026-2027', presidents_list: { eligible: true } },
        { academic_year: '2027-2028', presidents_list: { eligible: false } },
      ],
      terms: [
        { academic_year: '2026-2027', semester: '1', deans_list: { eligible: true } },
        { academic_year: '2026-2027', semester: '2', deans_list: { eligible: false, reason: 'GPA' } },
      ],
    };

    expect(awardsFrom(summary)).toEqual([
      { key: 'latin', name: 'Magna Cum Laude', when: 'Latin honors (on graduation)' },
      { key: 'president-2026-2027', name: "President's List", when: 'A.Y. 2026-2027' },
      { key: 'dean-2026-2027-1', name: "Dean's List", when: 'A.Y. 2026-2027, 1st semester' },
    ]);
  });

  test('nothing eligible, or no summary, means no awards', () => {
    expect(awardsFrom({ latin_honors: { eligible: false, honor: null }, years: [], terms: [] })).toEqual([]);
    expect(awardsFrom(undefined)).toEqual([]);
  });

  test('GWA and status formatting', () => {
    expect(formatGwa(1.875)).toBe('1.88');
    expect(formatGwa(null)).toBe('—');
    expect(subjectStatusBadge('Completed')).toContain('green');
    expect(subjectStatusBadge('Failed - Retake Required')).toContain('red');
  });
});

describe('archived subjects in the student roadmap (#92)', () => {
  const eligible = { status: 'Eligible to Take' };
  const archived = { status: 'Archived', archived: true };
  const passedArchived = { status: 'Completed', archived: true };

  test('"Eligible" never lists an archived subject', () => {
    expect(roadmapMatchesStatus(eligible, 'Eligible')).toBe(true);
    expect(roadmapMatchesStatus(archived, 'Eligible')).toBe(false);
    expect(roadmapMatchesStatus({ status: 'Eligible to Take', archived: true }, 'Eligible')).toBe(false);
  });

  test('"Archived" lists archived subjects, taken or not', () => {
    expect(roadmapMatchesStatus(archived, 'Archived')).toBe(true);
    expect(roadmapMatchesStatus(passedArchived, 'Archived')).toBe(true);
    expect(roadmapMatchesStatus(eligible, 'Archived')).toBe(false);
    expect(roadmapMatchesStatus(passedArchived, 'Completed')).toBe(true);
    expect(roadmapMatchesStatus(archived, '')).toBe(true);
  });

  test('the Archived status has its own badge', () => {
    expect(subjectStatusBadge('Archived')).toContain('amber');
  });
});
