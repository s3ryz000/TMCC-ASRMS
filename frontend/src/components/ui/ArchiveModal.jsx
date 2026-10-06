import { staffApi } from "../../lib/api/staffApi";
import { queryKeys } from '../../lib/react-query/queryKeys';
import { parseApiError } from '../../lib/api/errors';
import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { FiArchive, FiX } from 'react-icons/fi';

// onArchived (optional) runs after a successful archive, before onClose.
const ArchiveModal = ({ isOpen, onClose, student, onArchived }) => {
  const queryClient = useQueryClient();

  const [form, setForm] = useState({
    record_type: '',
    cabinet_no: '',
    shelf_no: '',
    folder_code: '',
    document_status: 'pending',
  });

  const [loading, setLoading] = useState(false);

  if (!isOpen || !student) return null;

  const handleArchive = async () => {
    try {
      setLoading(true);

      await staffApi.archiveStudent(student.student_id, form);

      onArchived?.();
      onClose();

      queryClient.invalidateQueries({
        queryKey: [...queryKeys.staff.all, "students"],
      });
    } catch (err) {
      alert(parseApiError(err).message);
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
            onClick={onClose}
            className="p-2 rounded-lg hover:bg-gray-100"
          >
            <FiX />
          </button>
        </div>

        {/* Body */}
        <div className="p-6 space-y-4">

          <p className="text-sm text-gray-500">
            You are about to archive <span className="font-medium text-gray-700">{student.name}</span>.
          </p>

          {/* Inputs */}
          <div className="grid grid-cols-1 gap-4">

            <Input label="Record Type"
              value={form.record_type}
              onChange={(v) => setForm({ ...form, record_type: v })}
            />

            <div className="grid grid-cols-2 gap-3">
              <Input label="Cabinet No"
                value={form.cabinet_no}
                onChange={(v) => setForm({ ...form, cabinet_no: v })}
              />

              <Input label="Shelf No"
                value={form.shelf_no}
                onChange={(v) => setForm({ ...form, shelf_no: v })}
              />
            </div>

            <Input label="Folder Code"
              value={form.folder_code}
              onChange={(v) => setForm({ ...form, folder_code: v })}
            />

            <select
              value={form.document_status}
              onChange={(e) => setForm({ ...form, document_status: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-tmcc/20 focus:border-tmcc"
            >
              <option value="pending">Pending</option>
              <option value="stored">Stored</option>
              <option value="released">Released</option>
            </select>

          </div>
        </div>

        {/* Footer */}
        <div className="flex justify-end gap-2 px-6 py-4 border-t bg-gray-50 rounded-b-2xl">

          <button
            onClick={onClose}
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


// 🔹 Reusable Input Component
const Input = ({ label, value, onChange }) => (
  <div>
    <label className="block text-xs font-medium text-gray-600 mb-1">
      {label}
    </label>
    <input
      value={value}
      onChange={(e) => onChange(e.target.value)}
      className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-tmcc/20 focus:border-tmcc"
    />
  </div>
);

export default ArchiveModal;