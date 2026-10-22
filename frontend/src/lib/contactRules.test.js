import { EMAIL_MESSAGE, MOBILE_MESSAGE, emailProblem, mobileProblem, normalizeMobile } from './contactRules';

describe('contact rules (#105)', () => {
  test('a mobile number typed either way is read as 09XXXXXXXXX', () => {
    expect(normalizeMobile('09171234567')).toBe('09171234567');
    expect(normalizeMobile('0917 123 4567')).toBe('09171234567');
    expect(normalizeMobile('+639171234567')).toBe('09171234567');
    expect(normalizeMobile('+63 917-123-4567')).toBe('09171234567');
  });

  test('valid and blank numbers pass; others get the message', () => {
    expect(mobileProblem('')).toBeNull();
    expect(mobileProblem('+63 917 123 4567')).toBeNull();
    for (const bad of ['0999999999', '091712345678', '046 123 4567', '+1 555 123 4567', '0917abc4567']) {
      expect(mobileProblem(bad)).toBe(MOBILE_MESSAGE);
    }
  });

  test('an e-mail address needs a domain', () => {
    expect(emailProblem('bea@gmail.com')).toBeNull();
    expect(emailProblem('bea@gmail')).toBe(EMAIL_MESSAGE);
    expect(emailProblem('bea gmail.com')).toBe(EMAIL_MESSAGE);
  });
});
