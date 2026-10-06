/**
 * Ending a session that expired on the server (#86): after 60 minutes idle or
 * 12 hours in total, any API call answers 401. The API client then clears the
 * stored session and AuthContext returns to the login page with this message.
 */
export const SESSION_EXPIRED_MESSAGE = 'Your session expired. Please sign in again.';

/**
 * Whether a response ends a session the user had. Only a 401 sent while a
 * token was stored counts, so the login page (no token) never shows the
 * message or redirects to itself, and a failed sign-in is not an "expiry".
 */
export function endsLiveSession(status, hadToken, url = '') {
  return status === 401 && Boolean(hadToken) && !/(^|\/)auth\/login\/?$/.test(url || '');
}
