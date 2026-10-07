import { passwordProblem, PASSWORD_HINT } from './passwordRule';

describe('Password rule hint (#87)', () => {
  test('refuses short, letters-only and numbers-only passwords', () => {
    expect(passwordProblem('password')).toBe(PASSWORD_HINT);
    expect(passwordProblem('abcd12345')).toBe(PASSWORD_HINT);
    expect(passwordProblem('bluerivergate')).toBe(PASSWORD_HINT);
    expect(passwordProblem('12345678901')).toBe(PASSWORD_HINT);
  });

  test('accepts 10+ characters with letters and numbers', () => {
    expect(passwordProblem('blue7river42')).toBeNull();
  });
});
