import React, { useEffect, useState } from 'react';
import Modal from '../ui/Modal';
import { parseApiError } from '../../lib/api/errors';

export const PURPOSE_MAX = 255;
export const COPIES_MIN = 1;
export const COPIES_MAX = 10;

/** The browser-side check; the server repeats it and has the final word. */
export function requestFormErrors({ purpose, copies }) {
  const errors = {};
  if (!String(purpose ?? '').trim()) errors.purpose = 'Enter the purpose of the request, e.g. employment or scholarship.';
  else if (String(purpose).length > PURPOSE_MAX) errors.purpose = `The purpose may not be longer than ${PURPOSE_MAX} characters.`;
  const n = Number(copies);
  if (!Number.isInteger(n) || n < COPIES_MIN || n > COPIES_MAX) errors.copies = `Enter ${COPIES_MIN} to ${COPIES_MAX} copies.`;
  return errors;
}

/**
 * Request Document (#93): the purpose (required) and number of copies,
 * asked before the request is created. `onSubmit({ purpose, copies })`
 * returns a promise; a 422 shows its messages next to the fields.
 */
const RequestDocumentModal = ({ document, onSubmit, onClose }) => {
  const [purpose, setPurpose] = useState('');
  const [copies, setCopies] = useState(1);
  const [errors, setErrors] = useState({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    setPurpose('');
    setCopies(1);
    setErrors({});
  }, [document?.docKey]);

  if (!document) return null;

  const handleSubmit = async (e) => {
    e.preventDefault();
    const clientErrors = requestFormErrors({ purpose, copies });
    setErrors(clientErrors);
    if (Object.keys(clientErrors).length > 0) return;

    setSubmitting(true);
    try {
      await onSubmit({ purpose: purpose.trim(), copies: Number(copies) });
      onClose();
    } catch (err) {
      const parsed = parseApiError(err);
      const next = {};
      Object.entries(parsed.errors || {}).forEach(([k, msgs]) => {
        next[k] = Array.isArray(msgs) ? msgs[0] : String(msgs);
      });
      if (!next.purpose && !next.copies) next.submit = parsed.message || 'Failed to submit request.';
      setErrors(next);
    } finally {
      setSubmitting(false);
    }
  };

  const inputClass = (error) =>
    `w-full py-2 px-3 rounded-lg border text-sm focus:outline-none focus:ring-2 focus:ring-tmcc/20 focus:border-tmcc ${
      error ? 'border-red-500 bg-red-50' : 'border-gray-300'
    }`;

  return (
    <Modal
      isOpen
      onClose={() => !submitting && onClose()}
      title={`Request ${document.name}`}
      titleId="request-document-title"
      maxWidth="max-w-md"
      closeOnBackdrop={!submitting}
    >
      <form onSubmit={handleSubmit} className="p-6 space-y-4" noValidate>
        {(document.ay !== 'All' || document.sem !== 'All') && (
          <p className="m-0 text-sm text-gray-600">
            {[document.ay, document.sem].filter((v) => v && v !== 'All').join(' · ')}
          </p>
        )}
        <div>
          <label htmlFor="request-purpose" className="block text-sm font-medium text-gray-700 mb-1">
            Purpose <span className="text-red-600" aria-hidden>*</span>
          </label>
          <textarea
            id="request-purpose"
            rows={3}
            maxLength={PURPOSE_MAX}
            value={purpose}
            onChange={(e) => setPurpose(e.target.value)}
            placeholder="e.g. Employment, scholarship application, transfer"
            className={inputClass(errors.purpose)}
            aria-invalid={!!errors.purpose}
            aria-describedby="request-purpose-hint"
            autoFocus
          />
          <p id="request-purpose-hint" className="m-0 mt-1 text-xs text-gray-500">
            {purpose.length}/{PURPOSE_MAX} characters
          </p>
          {errors.purpose && <p className="m-0 mt-1 text-xs text-red-600">{errors.purpose}</p>}
        </div>
        <div>
          <label htmlFor="request-copies" className="block text-sm font-medium text-gray-700 mb-1">
            Number of copies
          </label>
          <input
            id="request-copies"
            type="number"
            min={COPIES_MIN}
            max={COPIES_MAX}
            step={1}
            value={copies}
            onChange={(e) => setCopies(e.target.value)}
            className={`${inputClass(errors.copies)} max-w-[8rem]`}
            aria-invalid={!!errors.copies}
          />
          {errors.copies && <p className="m-0 mt-1 text-xs text-red-600">{errors.copies}</p>}
        </div>
        {errors.submit && <p className="m-0 text-sm text-red-600" role="alert">{errors.submit}</p>}
        <div className="flex justify-end gap-3 pt-2">
          <button
            type="button"
            onClick={onClose}
            disabled={submitting}
            className="py-2.5 px-5 rounded-lg text-sm font-medium bg-gray-200 text-gray-800 hover:bg-gray-300"
          >
            Cancel
          </button>
          <button
            type="submit"
            disabled={submitting}
            className="py-2.5 px-5 rounded-lg text-sm font-medium bg-tmcc text-white hover:bg-tmcc-dark disabled:opacity-60"
          >
            {submitting ? 'Submitting...' : 'Submit request'}
          </button>
        </div>
      </form>
    </Modal>
  );
};

export default RequestDocumentModal;
