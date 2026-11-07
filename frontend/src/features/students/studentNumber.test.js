import {
  cleanLetter,
  cleanPart,
  composeStudentNumber,
  isCompletePart,
  letterOf,
  numberPrefix,
  partOf,
  yearPrefix,
} from './studentNumber';

describe('student number helpers (#56)', () => {
  test('the year is the enrollment year read as written', () => {
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

  test('the letter is one capital A-Z', () => {
    expect(cleanLetter('a')).toBe('A');
    expect(cleanLetter('B')).toBe('B');
    expect(cleanLetter('1')).toBe('');
    expect(cleanLetter('AB')).toBe('');
  });

  test('letter, year and part join into the TMCC number', () => {
    expect(numberPrefix('A', '26')).toBe('A26-');
    expect(numberPrefix('A', '')).toBe('');
    expect(composeStudentNumber('A', '26', '0004')).toBe('A26-0004');
    expect(composeStudentNumber('b', '25', '0010')).toBe('B25-0010');
    expect(composeStudentNumber('A', '26', '004')).toBe('');
    expect(composeStudentNumber('A', '', '0004')).toBe('');
    expect(composeStudentNumber('', '26', '0004')).toBe('');
  });

  test('a full number splits back into its letter and part', () => {
    expect(partOf('A26-0005')).toBe('0005');
    expect(letterOf('B10-0007')).toBe('B');
    expect(partOf('260005')).toBe('');
    expect(letterOf('TMCC-2026-0001')).toBe('');
  });
});
