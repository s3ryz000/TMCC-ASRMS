import { editSectionOf, editStudentPaths, isDirty, previousOf } from './studentRoutes';

describe('Edit Student routes (#55)', () => {
  test('the hub and its two sections', () => {
    expect(editStudentPaths(12)).toEqual({
      hub: '/staff/students/12/edit',
      information: '/staff/students/12/edit/information',
      grades: '/staff/students/12/edit/grades',
    });
  });

  test('a path names its section; old links land on the hub', () => {
    expect(editSectionOf('/staff/students/12/edit')).toBe('hub');
    expect(editSectionOf('/staff/students/12/edit/')).toBe('hub');
    expect(editSectionOf('/staff/students/12/edit/information')).toBe('information');
    expect(editSectionOf('/staff/students/12/edit/grades')).toBe('grades');
    expect(editSectionOf('/staff/students/12/edit/other')).toBeNull();
    expect(editSectionOf('/staff/students')).toBeNull();
  });

  test('Previous always returns to the hub, never to the other section', () => {
    expect(previousOf(12)).toBe('/staff/students/12/edit');
  });

  test('the form is dirty only when a field differs from what was loaded', () => {
    const saved = { first_name: 'Bea', contact_number: '' };
    expect(isDirty({ first_name: 'Bea', contact_number: '' }, saved)).toBe(false);
    expect(isDirty({ first_name: 'Bea', contact_number: null }, saved)).toBe(false);
    expect(isDirty({ first_name: 'Bia', contact_number: '' }, saved)).toBe(true);
    expect(isDirty({ first_name: 'Bea' }, null)).toBe(false);
  });
});
