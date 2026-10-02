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
