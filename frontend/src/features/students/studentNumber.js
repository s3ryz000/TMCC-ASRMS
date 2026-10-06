/**
 * Student numbers (#56): the 2-digit enrollment year followed by 4 digits the
 * registrar types, e.g. 260001. Mirrors App\Support\StudentNumber.
 */

export const STUDENT_NUMBER_PATTERN = /^\d{2}\d{4}$/;

/**
 * "26" for an enrollment date of 2026-08-10. Read from the plain YYYY-MM-DD
 * value (#77), never through a Date object, so no timezone can move it.
 */
export function yearPrefix(enrollmentDate) {
  const match = /^(\d{4})-\d{2}-\d{2}/.exec(String(enrollmentDate ?? '').trim());
  return match ? match[1].slice(2) : '';
}

/** Only digits, at most 4: what the 4-digit input accepts. */
export const cleanPart = (value) => String(value ?? '').replace(/\D/g, '').slice(0, 4);

export const isCompletePart = (part) => /^\d{4}$/.test(String(part ?? ''));

/** "26" + "0004" = "260004"; empty until both halves are complete. */
export const composeStudentNumber = (prefix, part) =>
  /^\d{2}$/.test(prefix ?? '') && isCompletePart(part) ? `${prefix}${part}` : '';

/** The 4 digits after the prefix of a full number ("260005" -> "0005"). */
export const partOf = (number) => (STUDENT_NUMBER_PATTERN.test(String(number ?? '')) ? String(number).slice(2) : '');
