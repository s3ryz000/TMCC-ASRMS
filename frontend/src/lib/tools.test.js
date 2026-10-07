import { isPastDay } from './tools';

describe('Appointment calendar days (#83)', () => {
  test('days before today are past; today and later are not', () => {
    expect(isPastDay('2026-10-06', '2026-10-07')).toBe(true);
    expect(isPastDay('2026-09-30', '2026-10-07')).toBe(true);
    expect(isPastDay('2026-10-07', '2026-10-07')).toBe(false);
    expect(isPastDay('2026-10-08', '2026-10-07')).toBe(false);
    expect(isPastDay('', '2026-10-07')).toBe(false);
  });
});
