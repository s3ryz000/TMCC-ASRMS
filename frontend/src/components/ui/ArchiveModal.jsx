import { staffApi } from "../../lib/api/staffApi";
import { queryKeys } from '../../lib/react-query/queryKeys';
import { parseApiError } from '../../lib/api/errors';
import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { FiArchive, FiX } from 'react-icons/fi';
import ArchiveLocationFields, { EMPTY_ARCHIVE_LOCATION, fieldErrors } from '../staff/ArchiveLocationFields';

// onArchived (optional) runs after a successful archive, before onClose.
const ArchiveModal = ({ isOpen, onClose, student, onArchived }) => {
  const queryClient = useQueryClient();

  const [form, setForm] = useState(EMPTY_ARCHIVE_LOCATION);
  const [loading, setLoading] = useState(false);
  // The server's message per field (422, #85), or any other failure.
  const [errors, setErrors] = useState({});
  const [submitError, setSubmitError] = useState(null);

  if (!isOpen || !student) return null;

  const handleClose = () => {
    setErrors({});
    setSubmitError(null);
    onClose();
  };

  const handleArchive = async () => {
    try {
      setLoading(true);
      setErrors({});
      setSubmitError(null);

      await staffApi.archiveStudent(student.student_id, form);

      onArchived?.();
      handleClose();

      queryClient.invalidateQueries({
        queryKey: [...queryKeys.staff.all, "students"],
      });
    } catch (err) {
      const parsed = parseApiError(err);
      if (parsed.errors) {
        setErrors(fieldErrors(parsed));
      } else {
        setSubmitError(parsed.message);
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm">

      {/* Modal Card */}
      <div className="w-full max-w-lg bg-white rounded-2xl shadow-xl border border-gray-100 animate-[fadeIn_.2s_ease]">

        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b">
          <div className="flex items-center gap-2">
            <div className="p-2 rounded-lg bg-red-100 text-red-600">
              <FiArchive />
            </div>
            <h3 className="text-lg font-semibold text-gray-800">
              Archive Student
            </h3>
          </div>

          <button
            onClick={handleClose}
            className="p-2 rounded-lg hover:bg-gray-100"
            aria-label="Close"
          >
            <FiX />
          </button>
        </div>

        {/* Body */}
        <div className="p-6 space-y-4">

          <p className="text-sm text-gray-500">
            You are about to archive <span className="font-medium text-gray-700">{student.name}</span>.
          </p>

          <ArchiveLocationFields idPrefix="archive" value={form} onChange={setForm} errors={errors} />

          {submitError && <p className="text-sm text-red-600" role="alert">{submitError}</p>}
        </div>

        {/* Footer */}
        <div className="flex justify-end gap-2 px-6 py-4 border-t bg-gray-50 rounded-b-2xl">

          <button
            onClick={handleClose}
            disabled={loading}
            className="px-4 py-2 text-sm rounded-lg border border-gray-300 hover:bg-gray-100"
          >
            Cancel
          </button>

          <button
            onClick={handleArchive}
            disabled={loading}
            className="inline-flex items-center gap-2 px-4 py-2 text-sm rounded-lg bg-red-600 text-white hover:bg-red-700 disabled:opacity-50"
          >
            {loading ? "Archiving..." : (
              <>
                <FiArchive />
                Archive
              </>
            )}
          </button>

        </div>
      </div>
    </div>
  );
};

export default ArchiveModal;
