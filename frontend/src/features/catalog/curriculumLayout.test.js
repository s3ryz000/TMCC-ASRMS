import {
  CURRICULUM_TERMS,
  computeGridTotals,
  gridTotalsFromApi,
  impactRefusal,
  joinSubjectCode,
  prerequisiteCandidates,
  prerequisiteLabel,
  splitCurriculumErrors,
  termIndex,
  prerequisiteText,
} from './curriculumLayout';

describe('joinSubjectCode (registrar format, #70)', () => {
  test('joins prefix and part with nothing in between', () => {
    expect(joinSubjectCode('TPC', '11')).toBe('TPC11');
    expect(joinSubjectCode('GEC', '9')).toBe('GEC9');
  });

  test('trims spaces and dashes and uppercases', () => {
    expect(joinSubjectCode('tpc', ' 11 ')).toBe('TPC11');
    expect(joinSubjectCode('PATHFit', '-1')).toBe('PATHFIT1');
    expect(joinSubjectCode('NSTP', '1 a')).toBe('NSTP1A');
  });

  test('a prefix alone is a code; nothing gives an empty code', () => {
    expect(joinSubjectCode('HRM', '')).toBe('HRM');
    expect(joinSubjectCode(undefined, undefined)).toBe('');
  });
});

describe('computeGridTotals', () => {
  test('lists every Year 1-4 term, with year and program totals', () => {
    const totals = computeGridTotals([
      { yearLevel: 1, semester: 1, units: 3 },
      { yearLevel: 1, semester: 1, units: 4 },
      { yearLevel: 1, semester: 2, units: 3 },
      { yearLevel: 3, semester: 2, units: 6 },
    ]);

    expect(totals.terms).toHaveLength(8);
    expect(totals.terms[0]).toEqual({ yearLevel: 1, semester: 1, units: 7, subjects: 2, overMax: false });
    expect(totals.terms[5]).toEqual({ yearLevel: 3, semester: 2, units: 6, subjects: 1, overMax: false });
    expect(totals.terms[7]).toEqual({ yearLevel: 4, semester: 2, units: 0, subjects: 0, overMax: false });
    expect(totals.years.map((y) => y.units)).toEqual([10, 0, 6, 0]);
    expect(totals.program).toEqual({ units: 16, subjects: 4 });
  });

  test('flags a term above 26 units, not one at 26', () => {
    const nine = (units) => Array.from({ length: 9 }, () => ({ yearLevel: 2, semester: 1, units }));
    expect(computeGridTotals(nine(3)).terms[2].overMax).toBe(true); // 27
    expect(computeGridTotals([...nine(3).slice(1), { yearLevel: 2, semester: 1, units: 2 }]).terms[2]).toMatchObject({ units: 26, overMax: false });
  });

  test('matches the server totals shape', () => {
    const fromApi = gridTotalsFromApi({
      terms: [{ year_level: 1, semester: 1, units: 27, subjects: 9, over_max: true }],
      years: [{ year_level: 1, units: 27, subjects: 9 }],
      program: { units: 27, subjects: 9 },
    });

    expect(fromApi.terms).toHaveLength(CURRICULUM_TERMS.length);
    expect(fromApi.terms[0]).toEqual({ yearLevel: 1, semester: 1, units: 27, subjects: 9, overMax: true });
    expect(fromApi.terms[1]).toEqual({ yearLevel: 1, semester: 2, units: 0, subjects: 0, overMax: false });
    expect(fromApi.program).toEqual({ units: 27, subjects: 9 });
  });
});

describe('splitCurriculumErrors', () => {
  test('keys entry errors by index and program errors by field', () => {
    expect(splitCurriculumErrors({
      'entries.3.subject_id': ['GEC4 is already in BSIT, Year 1 1st semester.'],
      'entries.3.semester': ['The semester must be 1st or 2nd.'],
      'entries.0.new_subject.code': ['A subject with this code already exists.'],
      'program.code': ['A program with this code already exists.'],
      entries: ['Add at least one subject to the curriculum.'],
    })).toEqual({
      entries: {
        3: ['GEC4 is already in BSIT, Year 1 1st semester.', 'The semester must be 1st or 2nd.'],
        0: ['A subject with this code already exists.'],
      },
      program: { code: 'A program with this code already exists.' },
      other: ['Add at least one subject to the curriculum.'],
    });
  });
});

describe('prerequisite helpers (#27)', () => {
  const rows = [
    { key: 'a', code: 'THC1', yearLevel: 1, semester: 1 },
    { key: 'b', code: 'GEC5', yearLevel: 1, semester: 1 },
    { key: 'c', code: 'THC2', yearLevel: 1, semester: 2 },
    { key: 'd', code: 'THC4', yearLevel: 2, semester: 1 },
    { key: 'e', code: 'THC5', yearLevel: 2, semester: 1 },
  ];

  test('termIndex orders Year 1 1st, Year 1 2nd, Year 2 1st', () => {
    expect([termIndex(1, 1), termIndex(1, 2), termIndex(2, 1), termIndex(4, 2)]).toEqual([1, 2, 3, 8]);
  });

  test('only earlier terms are candidates, in term then code order', () => {
    expect(prerequisiteCandidates(rows, rows[3]).map((r) => r.code)).toEqual(['GEC5', 'THC1', 'THC2']);
    expect(prerequisiteCandidates(rows, rows[0])).toEqual([]);
  });

  test('labels join with commas for AND and "or" for OR', () => {
    expect(prerequisiteLabel(['THC1', 'GEC5'], 'AND')).toBe('GEC5, THC1');
    expect(prerequisiteLabel(['THC1', 'GEC5'], 'or')).toBe('GEC5 or THC1');
    expect(prerequisiteLabel([], 'AND')).toBe('');
  });
});

describe('impactRefusal (#28)', () => {
  const report = (count, requiredBy = []) => ({
    entry: { code: 'TPC1', program: 'BSTM' },
    students: { count },
    required_by: requiredBy.map((code) => ({ code })),
  });

  test('students with records block move and remove', () => {
    const message = "12 students already have records for TPC1 in BSTM; it can't be moved or removed.";
    expect(impactRefusal(report(12), 'move')).toBe(message);
    expect(impactRefusal(report(12), 'remove')).toBe(message);
    expect(impactRefusal(report(1), 'move')).toBe("1 student already has records for TPC1 in BSTM; it can't be moved or removed.");
  });

  test('a prerequisite in use blocks only removal', () => {
    expect(impactRefusal(report(0, ['TPC2', 'TPC3']), 'move')).toBeNull();
    expect(impactRefusal(report(0, ['TPC2', 'TPC3']), 'remove')).toBe("TPC1 can't be removed from BSTM: TPC2 and TPC3 list it as a prerequisite.");
    expect(impactRefusal(report(0), 'remove')).toBeNull();
  });
});

describe('program-completion subjects (#82)', () => {
  test('PRACTICUM reads "After all other subjects"', () => {
    expect(prerequisiteText({ prerequisites: [], requires_all_other_subjects: true }).linked).toBe('After all other subjects');
    expect(
      prerequisiteText({ prerequisites: [{ code: 'THC1' }], requires_all_other_subjects: true }).linked,
    ).toBe('THC1; After all other subjects');
    expect(prerequisiteText({ prerequisites: [{ code: 'THC1' }] }).linked).toBe('THC1');
  });
});
