/**
 * The password rule (#87), shown as a hint under every field that sets a
 * password. The server is the authority: it also refuses the username, the
 * e-mail name and common passwords, and its message is shown by the field.
 */
export const PASSWORD_HINT = 'Use at least 10 characters with letters and numbers.';

export const PASSWORD_MIN_LENGTH = 10;

/** The part of the rule the browser can check; null when it passes. */
export function passwordProblem(password) {
  if (
    password.length < PASSWORD_MIN_LENGTH
    || !/\p{L}/u.test(password)
    || !/\p{N}/u.test(password)
  ) {
    return PASSWORD_HINT;
  }
  return null;
}
