import { endsLiveSession, SESSION_EXPIRED_MESSAGE } from './sessionExpiry';

describe('Expired sessions (#86)', () => {
  test('a 401 while signed in ends the session', () => {
    expect(endsLiveSession(401, 'token-abc', '/staff/students')).toBe(true);
    expect(endsLiveSession(401, 'token-abc', '/user')).toBe(true);
    expect(endsLiveSession(401, 'token-abc', '/auth/logout')).toBe(true);
  });

  test('without a stored token it is not an expiry, so the login page never loops', () => {
    expect(endsLiveSession(401, null, '/user')).toBe(false);
    expect(endsLiveSession(401, '', '/user')).toBe(false);
  });

  test('the sign-in request itself is never an expiry', () => {
    expect(endsLiveSession(401, 'token-abc', '/auth/login')).toBe(false);
    expect(endsLiveSession(401, 'token-abc', 'http://localhost:8000/api/auth/login')).toBe(false);
  });

  test('other errors are not an expiry', () => {
    expect(endsLiveSession(403, 'token-abc', '/admin/users')).toBe(false);
    expect(endsLiveSession(422, 'token-abc', '/auth/change-password')).toBe(false);
    expect(endsLiveSession(500, 'token-abc', '/user')).toBe(false);
  });

  test('the message', () => {
    expect(SESSION_EXPIRED_MESSAGE).toBe('Your session expired. Please sign in again.');
  });
});
