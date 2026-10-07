import { requestAction } from './documentRequests';

describe('Request Documents actions (#90)', () => {
  test('an approved request offers its approval slip', () => {
    expect(requestAction('approved')).toBe('slip');
  });

  test('released keeps Download PDF; rejected or none can be requested again', () => {
    expect(requestAction('released')).toBe('download');
    expect(requestAction('rejected')).toBe('request');
    expect(requestAction(null)).toBe('request');
    expect(requestAction('pending')).toBe('processing');
  });
});
