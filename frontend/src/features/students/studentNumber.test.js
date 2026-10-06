import { cleanPart, composeStudentNumber, isCompletePart, partOf, yearPrefix } from './studentNumber';

describe('student number helpers (#56)', () => {
  test('the prefix is the enrollment year read as written', () => {
    expect(yearPrefix('2026-08-10')).toBe('26');
    expect(yearPrefix('2027-01-01 00:00:00')).toBe('27');
    expect(yearPrefix('2026-12-31')).toBe('26'); // no timezone shift into 2027
    expect(yearPrefix('')).toBe('');
    expect(yearPrefix(undefined)).toBe('');
  });

  test('the part keeps only 4 digits', () => {
    expect(cleanPart('00a4')).toBe('004');
    expect(cleanPart('123456')).toBe('1234');
    expect(isCompletePart('0004')).toBe(true);
    expect(isCompletePart('004')).toBe(false);
  });

  test('prefix and part join into the 6-digit number', () => {
    expect(composeStudentNumber('26', '0004')).toBe('260004');
    expect(composeStudentNumber('26', '004')).toBe('');
    expect(composeStudentNumber('', '0004')).toBe('');
    expect(partOf('260005')).toBe('0005');
    expect(partOf('TMCC-2026-0001')).toBe('');
  });
});
