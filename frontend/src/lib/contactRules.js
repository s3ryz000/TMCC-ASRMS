/**
 * Contact number and e-mail checks (#105), the same rules as the server's
 * ContactRules. The server is the authority; these give the message before
 * the form is sent.
 */
export const MOBILE_MESSAGE = 'Enter a mobile number as 09XXXXXXXXX or +639XXXXXXXXX.';

export const EMAIL_MESSAGE = 'Enter a valid email address, e.g. name@gmail.com.';

/** 09XXXXXXXXX for a mobile number typed either way; '' for blank. */
export function normalizeMobile(value) {
  const digits = String(value ?? '').replace(/[\s\-().]/g, '');
  const m = digits.match(/^\+?639(\d{9})$/);
  return m ? `09${m[1]}` : digits;
}

/** The message for an invalid mobile number; null when valid or blank (it is optional). */
export function mobileProblem(value) {
  const normalized = normalizeMobile(value);
  if (normalized === '') return null;
  return /^09\d{9}$/.test(normalized) ? null : MOBILE_MESSAGE;
}

/** The message for an invalid e-mail address; null when it looks valid. */
export function emailProblem(value) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value ?? '').trim()) ? null : EMAIL_MESSAGE;
}
