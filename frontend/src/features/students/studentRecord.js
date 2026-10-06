/**
 * Read-only views of a student's record (#57). Awards are never decided here:
 * they are listed exactly as GET /staff/students/{id}/academic-summary
 * computes them (AcademicStandingService), so editing grades is the only way
 * they change.
 */

const semesterName = (semester) => {
  const n = Number(semester);
  if (n === 1) return '1st semester';
  if (n === 2) return '2nd semester';
  return semester ? `semester ${semester}` : '';
};

/**
 * Every award the backend marks eligible: Latin honors, President's List per
 * academic year, Dean's List per term.
 *
 * @param {object} summary the `summary` of the academic-summary response
 * @returns {Array<{ key: string, name: string, when: string }>}
 */
export function awardsFrom(summary) {
  if (!summary) return [];
  const awards = [];

  if (summary.latin_honors?.eligible && summary.latin_honors.honor) {
    awards.push({ key: 'latin', name: summary.latin_honors.honor, when: 'Latin honors (on graduation)' });
  }
  (summary.years ?? []).forEach((year) => {
    if (year.presidents_list?.eligible) {
      awards.push({ key: `president-${year.academic_year}`, name: "President's List", when: `A.Y. ${year.academic_year}` });
    }
  });
  (summary.terms ?? []).forEach((term) => {
    if (term.deans_list?.eligible) {
      awards.push({
        key: `dean-${term.academic_year}-${term.semester}`,
        name: "Dean's List",
        when: `A.Y. ${term.academic_year}, ${semesterName(term.semester)}`,
      });
    }
  });

  return awards;
}

/** "1.88"; "—" when there is no GWA yet. */
export const formatGwa = (value) => {
  if (value == null || value === '') return '—';
  const n = Number(value);
  return Number.isFinite(n) ? n.toFixed(2) : String(value);
};

/** Badge colours for the roadmap statuses AcademicProgressionService returns. */
export function subjectStatusBadge(status) {
  if (status === 'Completed') return 'bg-green-100 text-green-800';
  if (status === 'Currently Enrolled' || status === 'Enrolled') return 'bg-blue-100 text-blue-800';
  if (String(status ?? '').startsWith('Failed')) return 'bg-red-100 text-red-800';
  if (status === 'Incomplete') return 'bg-orange-100 text-orange-800';
  if (String(status ?? '').includes('Blocked')) return 'bg-gray-100 text-gray-800';
  return 'bg-gray-50 text-gray-600';
}
