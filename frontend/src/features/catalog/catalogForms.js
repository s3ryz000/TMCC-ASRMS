/**
 * Field definitions and client-side validation for the subject and program
 * forms. The limits mirror the backend's SaveSubjectRequest and
 * SaveProgramRequest so most mistakes are caught before a round trip; the
 * server remains the authority (uniqueness, units locked once graded).
 */

export const SUBJECT_FIELDS = [
  { name: 'code', label: 'Subject code', placeholder: 'IT 101', maxLength: 20, required: true },
  { name: 'title', label: 'Descriptive title', placeholder: 'Introduction to Computing', maxLength: 150, required: true },
  { name: 'units', label: 'Units', type: 'number', min: 0, max: 12, required: true },
  { name: 'description', label: 'Description (optional)', maxLength: 255, multiline: true },
];

export const PROGRAM_FIELDS = [
  { name: 'code', label: 'Program code', placeholder: 'BSIT', maxLength: 20, required: true },
  { name: 'name', label: 'Program name', placeholder: 'Bachelor of Science in Information Technology', maxLength: 150, required: true },
  { name: 'description', label: 'Description (optional)', maxLength: 255, multiline: true },
];

export const EMPTY_SUBJECT = { code: '', title: '', units: 3, description: '' };
export const EMPTY_PROGRAM = { code: '', name: '', description: '' };

/**
 * @param {Array} fields  SUBJECT_FIELDS or PROGRAM_FIELDS
 * @param {object} form
 * @returns {object} field name => message; empty when valid.
 */
export function validateCatalogForm(fields, form) {
  const errors = {};

  fields.forEach((field) => {
    const raw = form[field.name];
    const value = typeof raw === 'string' ? raw.trim() : raw;
    const empty = value === '' || value === null || value === undefined;

    if (field.required && empty) {
      errors[field.name] = `${field.label} is required.`;
      return;
    }
    if (empty) return;

    if (field.type === 'number') {
      const n = Number(value);
      if (!Number.isInteger(n)) {
        errors[field.name] = `${field.label} must be a whole number.`;
      } else if (n < field.min || n > field.max) {
        errors[field.name] = `${field.label} must be between ${field.min} and ${field.max}.`;
      }
      return;
    }

    if (field.maxLength && String(value).length > field.maxLength) {
      errors[field.name] = `${field.label} must be at most ${field.maxLength} characters.`;
    }
  });

  return errors;
}

/** Trim strings, send numbers as numbers and blank optionals as null. */
export function toPayload(fields, form) {
  return Object.fromEntries(
    fields.map((field) => {
      const raw = form[field.name];
      if (field.type === 'number') return [field.name, raw === '' ? null : Number(raw)];
      const value = typeof raw === 'string' ? raw.trim() : raw;
      return [field.name, value === '' ? null : value];
    }),
  );
}

/** Laravel's { field: [messages] } → { field: firstMessage } */
export function firstErrors(serverErrors) {
  return Object.fromEntries(
    Object.entries(serverErrors || {}).map(([key, messages]) => [key, Array.isArray(messages) ? messages[0] : String(messages)]),
  );
}
