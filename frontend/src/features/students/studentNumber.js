/**
 * Student numbers (#56) in TMCC's own format: a capital letter, the 2-digit
 * enrollment year, a hyphen and 4 digits the registrar types, e.g. A26-0001.
 * Current students are "A"; older records use other letters, so any A-Z is
 * accepted (what the letter stands for is to be confirmed with the TMCC
 * Registrar). Mirrors App\Support\StudentNumber.
 */

export const STUDENT_NUMBER_PATTERN = /^[A-Z]\d{2}-\d{4}$/;

/** The letter the New Student form starts with. */
export const DEFAULT_LETTER = 'A';

/** Every letter the registrar can pick. */
export const LETTERS = Array.from({ length: 26 }, (_, i) => String.fromCharCode(65 + i));

/**
 * "26" for an enrollment date of 2026-08-10. Read from the plain YYYY-MM-DD
 * value (#77), never through a Date object, so no timezone can move it.
 */
export function yearPrefix(enrollmentDate) {
  const match = /^(\d{4})-\d{2}-\d{2}/.exec(String(enrollmentDate ?? '').trim());
  return match ? match[1].slice(2) : '';
}

/** One capital letter, or '' for anything else. */
export const cleanLetter = (value) => {
  const letter = String(value ?? '').trim().toUpperCase();
  return /^[A-Z]$/.test(letter) ? letter : '';
};

/** Only digits, at most 4: what the 4-digit input accepts. */
export const cleanPart = (value) => String(value ?? '').replace(/\D/g, '').slice(0, 4);

export const isCompletePart = (part) => /^\d{4}$/.test(String(part ?? ''));

/** What is shown before the 4-digit input: "A" + "26" = "A26-"; '' without a year. */
export const numberPrefix = (letter, year) =>
  cleanLetter(letter) && /^\d{2}$/.test(year ?? '') ? `${cleanLetter(letter)}${year}-` : '';

/** "A" + "26" + "0004" = "A26-0004"; empty until every part is complete. */
export const composeStudentNumber = (letter, year, part) => {
  const prefix = numberPrefix(letter, year);
  return prefix && isCompletePart(part) ? `${prefix}${part}` : '';
};

/** The 4 digits at the end of a full number ("A26-0005" -> "0005"). */
export const partOf = (number) => (STUDENT_NUMBER_PATTERN.test(String(number ?? '')) ? String(number).slice(4) : '');

/** The letter of a full number ("B10-0007" -> "B"); '' when it isn't one. */
export const letterOf = (number) => (STUDENT_NUMBER_PATTERN.test(String(number ?? '')) ? String(number)[0] : '');
