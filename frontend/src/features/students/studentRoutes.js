/**
 * Where the registrar's student screens live (#55). Every page loads the
 * student from the API by the id in the path; nothing else about the student
 * travels in the URL or router state.
 */

export const EDIT_SECTIONS = ['information', 'grades'];

/** /staff/students/12/edit, …/edit/information, …/edit/grades */
export function editStudentPaths(id, basePath = '/staff') {
  const hub = `${basePath}/students/${encodeURIComponent(id)}/edit`;
  return { hub, information: `${hub}/information`, grades: `${hub}/grades` };
}

/** The Edit Student section a path points at: 'hub', 'information', 'grades' or null. */
export function editSectionOf(pathname) {
  const match = /\/students\/[^/]+\/edit(?:\/([^/]+))?\/?$/.exec(String(pathname ?? ''));
  if (!match) return null;
  if (!match[1]) return 'hub';
  return EDIT_SECTIONS.includes(match[1]) ? match[1] : null;
}

/**
 * Where a student's read-only record lives, and where non-registrars and Back
 * links go. Student Records for now; Manage Records' record page with #57.
 */
export const studentListPath = () => '/staff/students';

/**
 * Where Previous goes from an Edit Student section: always the hub, never the
 * other section.
 */
export const previousOf = (id, basePath = '/staff') => editStudentPaths(id, basePath).hub;

/** Whether the Information form differs from what was loaded. */
export function isDirty(form, saved) {
  if (!saved) return false;
  return Object.keys(saved).some((key) => String(form?.[key] ?? '') !== String(saved[key] ?? ''));
}
