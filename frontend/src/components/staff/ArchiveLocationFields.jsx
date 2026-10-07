import React from 'react';

/**
 * The archive location fields (record type, cabinet, shelf, folder code,
 * document status), shared by Archive Student (#85) and Edit location (#97).
 * `errors` holds the server's message per field and is shown under it.
 */
export const EMPTY_ARCHIVE_LOCATION = {
  record_type: '',
  cabinet_no: '',
  shelf_no: '',
  folder_code: '',
  document_status: 'pending',
};

export const DOCUMENT_STATUSES = [
  ['pending', 'Pending'],
  ['stored', 'Stored'],
  ['released', 'Released'],
];

/** The first message per field from a parsed 422. */
export const fieldErrors = (parsed) => {
  const next = {};
  Object.entries(parsed?.errors || {}).forEach(([k, msgs]) => {
    next[k] = Array.isArray(msgs) ? msgs[0] : String(msgs);
  });
  return next;
};

const inputClass = (error) =>
  `w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-tmcc/20 focus:border-tmcc ${
    error ? 'border-red-500 bg-red-50' : 'border-gray-300'
  }`;

const Field = ({ id, label, error, children }) => (
  <div>
    <label htmlFor={id} className="block text-xs font-medium text-gray-600 mb-1">
      {label}
    </label>
    {children}
    {error && (
      <p id={`${id}-error`} className="mt-1 text-xs text-red-600">
        {error}
      </p>
    )}
  </div>
);

const ArchiveLocationFields = ({ idPrefix, value, onChange, errors = {} }) => {
  const set = (key) => (e) => onChange({ ...value, [key]: e.target.value });
  const text = (key, label) => {
    const id = `${idPrefix}-${key}`;
    return (
      <Field id={id} label={label} error={errors[key]}>
        <input
          id={id}
          value={value[key] ?? ''}
          onChange={set(key)}
          className={inputClass(errors[key])}
          aria-invalid={!!errors[key]}
          aria-describedby={errors[key] ? `${id}-error` : undefined}
        />
      </Field>
    );
  };

  // A status saved before the list existed is kept selectable.
  const statuses = DOCUMENT_STATUSES.some(([v]) => v === value.document_status) || !value.document_status
    ? DOCUMENT_STATUSES
    : [...DOCUMENT_STATUSES, [value.document_status, value.document_status]];
  const statusId = `${idPrefix}-document_status`;

  return (
    <div className="grid grid-cols-1 gap-4">
      {text('record_type', 'Record Type')}
      <div className="grid grid-cols-2 gap-3">
        {text('cabinet_no', 'Cabinet No')}
        {text('shelf_no', 'Shelf No')}
      </div>
      {text('folder_code', 'Folder Code')}
      <Field id={statusId} label="Document Status" error={errors.document_status}>
        <select
          id={statusId}
          value={value.document_status ?? ''}
          onChange={set('document_status')}
          className={inputClass(errors.document_status)}
          aria-invalid={!!errors.document_status}
        >
          {statuses.map(([v, label]) => (
            <option key={v} value={v}>
              {label}
            </option>
          ))}
        </select>
      </Field>
    </div>
  );
};

export default ArchiveLocationFields;
