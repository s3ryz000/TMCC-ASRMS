import React, { useEffect, useState } from 'react';
import Modal from '../ui/Modal';
import { staffApi } from '../../lib/api/staffApi';
import { parseApiError } from '../../lib/api/errors';
import ArchiveLocationFields, { EMPTY_ARCHIVE_LOCATION, fieldErrors } from './ArchiveLocationFields';

const pick = (archive) =>
  Object.fromEntries(Object.keys(EMPTY_ARCHIVE_LOCATION).map((k) => [k, archive?.[k] ?? EMPTY_ARCHIVE_LOCATION[k]]));

/**
 * Registrar: change where a student's paper records are kept (#97). Starts
 * from the current location; the server validates (#85) and logs old → new.
 */
const EditArchiveLocationModal = ({ isOpen, onClose, studentId, archive, onSaved }) => {
  const [form, setForm] = useState(() => pick(archive));
  const [errors, setErrors] = useState({});
  const [submitError, setSubmitError] = useState(null);
  const [saving, setSaving] = useState(false);

  // Each opening starts from the location currently on record.
  useEffect(() => {
    if (isOpen) {
      setForm(pick(archive));
      setErrors({});
      setSubmitError(null);
    }
  }, [isOpen, archive]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    setSubmitError(null);
    try {
      const res = await staffApi.updateArchiveLocation(studentId, form);
      onSaved?.(res);
      onClose();
    } catch (err) {
      const parsed = parseApiError(err);
      if (parsed.errors) setErrors(fieldErrors(parsed));
      else setSubmitError(parsed.message || 'Could not save the archive location.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="Edit archive location" titleId="edit-archive-location-title" maxWidth="max-w-lg">
      <form onSubmit={handleSubmit} className="p-6">
        <ArchiveLocationFields idPrefix="edit-archive" value={form} onChange={setForm} errors={errors} />
        {submitError && <p className="mt-4 mb-0 text-sm text-red-600" role="alert">{submitError}</p>}
        <div className="flex gap-3 mt-6 justify-end">
          <button
            type="button"
            onClick={onClose}
            disabled={saving}
            className="py-2.5 px-5 rounded-lg text-sm font-medium bg-gray-200 text-gray-800 hover:bg-gray-300"
          >
            Cancel
          </button>
          <button
            type="submit"
            disabled={saving}
            className="py-2.5 px-5 rounded-lg text-sm font-medium bg-tmcc text-white hover:bg-tmcc-dark disabled:opacity-70"
          >
            {saving ? 'Saving...' : 'Save location'}
          </button>
        </div>
      </form>
    </Modal>
  );
};

export default EditArchiveLocationModal;
