import React, { useEffect, useState } from 'react';
import Modal from '../ui/Modal';
import { parseApiError } from '../../lib/api/errors';
import { firstErrors, toPayload, validateCatalogForm } from '../../features/catalog/catalogForms';

const inputBase = 'w-full py-2.5 px-4 rounded-lg border text-base focus:outline-none focus:ring-2 focus:ring-tmcc/20 focus:border-tmcc';
const inputError = 'border-red-500 bg-red-50';
const inputNormal = 'border-gray-300';

/**
 * Create/edit form for a catalogue record (subject or program), driven by a
 * field list from features/catalog/catalogForms.
 *
 * onSubmit(payload) must return a promise; a 422 response's field errors are
 * shown next to their inputs, anything else is passed to onError. An optional
 * notice is shown above the fields (e.g. how widely the record is used).
 */
const CatalogFormModal = ({ isOpen, onClose, title, idPrefix, fields, initialValues, submitLabel, onSubmit, onError, notice }) => {
  const [form, setForm] = useState(initialValues);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (isOpen) {
      setForm(initialValues);
      setErrors({});
    }
  }, [isOpen, initialValues]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    const clientErrors = validateCatalogForm(fields, form);
    setErrors(clientErrors);
    if (Object.keys(clientErrors).length > 0) return;

    setSaving(true);
    try {
      await onSubmit(toPayload(fields, form));
    } catch (err) {
      const parsed = parseApiError(err);
      if (parsed.errors) {
        setErrors(firstErrors(parsed.errors));
      } else {
        onError?.(parsed.message);
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={title} titleId={`${idPrefix}-title`} maxWidth="max-w-lg">
      <form onSubmit={handleSubmit} className="p-6" noValidate>
        {notice && (
          <div className="mb-4 p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-900 text-sm" role="note">
            {notice}
          </div>
        )}
        <div className="space-y-4">
          {fields.map((field) => {
            const id = `${idPrefix}-${field.name}`;
            const error = errors[field.name];
            const className = `${inputBase} ${error ? inputError : inputNormal}`;
            const common = {
              id,
              value: form[field.name] ?? '',
              onChange: (e) => setForm((f) => ({ ...f, [field.name]: e.target.value })),
              className,
              placeholder: field.placeholder,
              'aria-invalid': !!error,
              'aria-describedby': error ? `${id}-error` : undefined,
            };

            return (
              <div key={field.name}>
                <label htmlFor={id} className="block text-sm font-medium text-gray-700 mb-1">{field.label}</label>
                {field.multiline ? (
                  <textarea {...common} rows={3} />
                ) : (
                  <input {...common} type={field.type || 'text'} min={field.min} max={field.max} step={field.type === 'number' ? 1 : undefined} />
                )}
                {error && <p id={`${id}-error`} className="mt-1 text-xs text-red-600">{error}</p>}
              </div>
            );
          })}
        </div>
        <div className="flex gap-3 mt-6 justify-end">
          <button type="button" onClick={onClose} className="py-2.5 px-5 rounded-lg text-sm font-medium bg-gray-200 text-gray-800 hover:bg-gray-300">
            Cancel
          </button>
          <button type="submit" disabled={saving} className="py-2.5 px-5 rounded-lg text-sm font-medium bg-tmcc text-white hover:bg-tmcc-dark focus:ring-2 focus:ring-tmcc/30 disabled:opacity-70">
            {saving ? 'Saving...' : submitLabel}
          </button>
        </div>
      </form>
    </Modal>
  );
};

export default CatalogFormModal;
