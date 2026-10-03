/**
 * Turns the rows of GET /staff/programs/{id}/curriculum into the year /
 * semester layout of a printed prospectus. Pure functions only, so the page
 * stays a thin renderer.
 */

const YEAR_LABELS = { 1: 'FIRST YEAR', 2: 'SECOND YEAR', 3: 'THIRD YEAR', 4: 'FOURTH YEAR' };
const SEMESTER_LABELS = { 1: 'FIRST SEMESTER', 2: 'SECOND SEMESTER', 3: 'SUMMER' };

export const yearLabel = (year) => YEAR_LABELS[year] ?? `YEAR ${year}`;
export const semesterLabel = (semester) => SEMESTER_LABELS[semester] ?? `TERM ${semester}`;

/** Natural order, so TPC2 comes before TPC10. */
export const compareCodes = (a, b) =>
  String(a ?? '').localeCompare(String(b ?? ''), undefined, { numeric: true, sensitivity: 'base' });

/** Round to 2 decimals so 0.1 + 0.2 style noise never shows. */
const round2 = (n) => Math.round((Number(n) || 0) * 100) / 100;

/** "3", "1.5", "21.5" — never "21.500000000000004". */
export const formatUnits = (units) => String(round2(units));

export const sumUnits = (values) => round2(values.reduce((total, units) => total + (Number(units) || 0), 0));

/**
 * The Pre-requisites cell: linked codes joined by ", " (AND) or " or " (OR),
 * plus any prerequisites the seeder could not link to a subject, as written.
 */
export function prerequisiteText(row) {
  const codes = (row.prerequisites ?? []).map((p) => p.code).filter(Boolean).sort(compareCodes);
  const joiner = String(row.prerequisite_logic ?? '').toUpperCase() === 'OR' ? ' or ' : ', ';

  const raw = row.unresolved_prerequisites;
  const unresolved = (Array.isArray(raw) ? raw : raw ? [raw] : []).map(String).filter((s) => s.trim() !== '');

  return { linked: codes.join(joiner), unresolved: unresolved.join(', ') };
}

// ── Curriculum builder (#70) ────────────────────────────────────────────────

/** AcademicLoadValidationService::MAXIMUM_UNITS on the server. */
export const MAXIMUM_UNITS = 26;

/** Every term a curriculum can use: Year 1-4, 1st and 2nd semester (no summer). */
export const CURRICULUM_TERMS = [1, 2, 3, 4].flatMap((yearLevel) => [1, 2].map((semester) => ({ yearLevel, semester })));

/** "Year 1, 1st semester" */
export const termLabel = (yearLevel, semester) => `Year ${yearLevel}, ${Number(semester) === 1 ? '1st' : '2nd'} semester`;

/**
 * A subject code in the registrar's format (2 Oct): the CHED prefix and the
 * registrar's part joined with nothing in between, uppercase, without spaces
 * or dashes. TPC + 11 -> TPC11, GEC + " 9" -> GEC9. Mirrors Subject::formatCode.
 */
export const joinSubjectCode = (prefix, part) =>
  `${prefix ?? ''}${part ?? ''}`.replace(/[\s\-‐-―]+/g, '').toUpperCase();

/**
 * Units per term, per year and for the program, for the builder grid. Every
 * Year 1-4 term is listed, empty ones with 0; a term over the maximum is
 * flagged as the server's CurriculumTotals does.
 *
 * @param {Array<{ yearLevel: number, semester: number, units: number }>} rows
 * @returns {{ terms: Array<{ yearLevel, semester, units, subjects, overMax }>, years: Array<{ yearLevel, units, subjects }>, program: { units, subjects } }}
 */
export function computeGridTotals(rows, maximumUnits = MAXIMUM_UNITS) {
  const terms = CURRICULUM_TERMS.map(({ yearLevel, semester }) => {
    const inTerm = (rows ?? []).filter((r) => Number(r.yearLevel) === yearLevel && Number(r.semester) === semester);
    const units = sumUnits(inTerm.map((r) => r.units));
    return { yearLevel, semester, units, subjects: inTerm.length, overMax: units > maximumUnits };
  });

  return withYearAndProgramTotals(terms);
}

/** The server's `totals` (#26) in the same shape as computeGridTotals, every term listed. */
export function gridTotalsFromApi(totals) {
  const byTerm = new Map((totals?.terms ?? []).map((t) => [`${t.year_level}-${t.semester}`, t]));
  const terms = CURRICULUM_TERMS.map(({ yearLevel, semester }) => {
    const t = byTerm.get(`${yearLevel}-${semester}`);
    return { yearLevel, semester, units: Number(t?.units) || 0, subjects: Number(t?.subjects) || 0, overMax: Boolean(t?.over_max) };
  });

  return withYearAndProgramTotals(terms);
}

function withYearAndProgramTotals(terms) {
  const years = [1, 2, 3, 4].map((yearLevel) => {
    const inYear = terms.filter((t) => t.yearLevel === yearLevel);
    return { yearLevel, units: sumUnits(inYear.map((t) => t.units)), subjects: inYear.reduce((n, t) => n + t.subjects, 0) };
  });

  return {
    terms,
    years,
    program: { units: sumUnits(years.map((y) => y.units)), subjects: years.reduce((n, y) => n + y.subjects, 0) },
  };
}

/**
 * Splits a 422's errors from POST /staff/curriculums: `entries.3.subject_id`
 * goes to entry 3, `program.code` to the program form, anything else to `other`.
 *
 * @returns {{ entries: Object<number, string[]>, program: Object<string, string>, other: string[] }}
 */
export function splitCurriculumErrors(errors) {
  const result = { entries: {}, program: {}, other: [] };

  Object.entries(errors ?? {}).forEach(([key, messages]) => {
    const list = Array.isArray(messages) ? messages : [String(messages)];
    const entry = key.match(/^entries\.(\d+)\./);
    const program = key.match(/^program\.(\w+)$/);

    if (entry) {
      const index = Number(entry[1]);
      result.entries[index] = [...(result.entries[index] ?? []), ...list];
    } else if (program) {
      result.program[program[1]] = list[0];
    } else {
      result.other.push(...list);
    }
  });

  return result;
}

/**
 * Why an impact report (#28) refuses a move or removal, worded as the server's
 * 409; null when the action is allowed.
 *
 * @param {object} report GET /staff/curriculum/{id}/impact
 * @param {'move'|'remove'} action
 */
export function impactRefusal(report, action) {
  if (!report) return null;
  const { code, program } = report.entry ?? {};
  const count = Number(report.students?.count) || 0;

  if (count > 0) {
    const who = count === 1 ? '1 student already has' : `${count} students already have`;
    return `${who} records for ${code} in ${program}; it can't be moved or removed.`;
  }

  if (action === 'remove' && (report.required_by ?? []).length > 0) {
    const codes = report.required_by.map((r) => r.code);
    const list = codes.length > 1 ? `${codes.slice(0, -1).join(', ')} and ${codes[codes.length - 1]}` : codes[0];
    return `${code} can't be removed from ${program}: ${list} ${codes.length > 1 ? 'list' : 'lists'} it as a prerequisite.`;
  }

  return null;
}

/**
 * @param {Array} rows curriculum rows ({ year_level, semester, subject, prerequisites, ... })
 * @returns {{ years: Array, totalUnits: number, subjectCount: number }}
 *   years: [{ yearLevel, label, totalUnits, semesters: [{ semester, label, totalUnits, subjects: [...] }] }]
 */
export function buildCurriculumLayout(rows) {
  const byYear = new Map();

  (rows ?? []).forEach((row) => {
    const yearLevel = Number(row.year_level);
    const semester = Number(row.semester);
    if (!byYear.has(yearLevel)) byYear.set(yearLevel, new Map());
    const bySemester = byYear.get(yearLevel);
    if (!bySemester.has(semester)) bySemester.set(semester, []);

    const { linked, unresolved } = prerequisiteText(row);
    bySemester.get(semester).push({
      id: row.id,
      code: row.subject?.code ?? '',
      title: row.subject?.title ?? '',
      units: Number(row.subject?.units) || 0,
      // Archived subjects stay in the prospectus and its totals, marked (#68).
      archived: Boolean(row.subject?.archived),
      prerequisites: linked,
      unresolvedPrerequisites: unresolved,
    });
  });

  const years = [...byYear.keys()].sort((a, b) => a - b).map((yearLevel) => {
    const bySemester = byYear.get(yearLevel);
    const semesters = [...bySemester.keys()].sort((a, b) => a - b).map((semester) => {
      const subjects = bySemester.get(semester).sort((a, b) => compareCodes(a.code, b.code));
      return { semester, label: semesterLabel(semester), subjects, totalUnits: sumUnits(subjects.map((s) => s.units)) };
    });
    return { yearLevel, label: yearLabel(yearLevel), semesters, totalUnits: sumUnits(semesters.map((s) => s.totalUnits)) };
  });

  return {
    years,
    totalUnits: sumUnits(years.map((y) => y.totalUnits)),
    subjectCount: years.reduce((n, y) => n + y.semesters.reduce((m, s) => m + s.subjects.length, 0), 0),
  };
}
