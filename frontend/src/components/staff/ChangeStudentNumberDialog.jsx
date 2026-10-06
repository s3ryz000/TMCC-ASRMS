import React, { useState } from 'react';
import Modal from '../ui/Modal';
import StudentNumberField from './StudentNumberField';
import { staffApi } from '../../lib/api/staffApi';
import { parseApiError } from '../../lib/api/errors';
import { composeStudentNumber, yearPrefix } from '../../features/students/studentNumber';

/**
 * Change Student Number (#56, registrar only): a new number with the live
 * availability check and a required reason. The login username changes with
 * it; on success the new login is shown so it can be given to the student.
 *
 * enrollmentDate: the student's saved enrollment date (YYYY-MM-DD), which the
 * server checks the new number's year against.
 */
const ChangeStudentNumberDialog = ({ studentId, currentNumber, enrollmentDate, onChanged, onClose }) => {
  const prefix = yearPrefix(enrollmentDate);
  const [part, setPart] = useState('');
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState({});
  const [message, setMessage] = useState(null);
  const [saving, setSaving] = useState(false);
  const [result, setResult] = useState(null);

  const number = composeStudentNumber(prefix, part);

  const save = async (e) => {
    e.preventDefault();
    const clientErrors = {};
    if (!number) clientErrors.student_number = 'Enter the 4 digits after the year.';
    if (reason.trim().length < 5) clientErrors.reason = 'Give a reason for the change; it is kept in the audit log.';
    setErrors(clientErrors);
    setMessage(null);
    if (Object.keys(clientErrors).length) return;

    setSaving(true);
    try {
      const res = await staffApi.changeStudentNumber(studentId, { student_number: number, reason: reason.trim() });
      setResult(res);
      onChanged(res);
    } catch (err) {
      const parsed = parseApiError(err);
      if (parsed.errors) {
        setErrors(Object.fromEntries(Object.entries(parsed.errors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : String(v)])));
      } else {
        setMessage(parsed.message);
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal isOpen onClose={() => !saving && onClose()} title="Change Student Number" titleId="change-student-number-title" maxWidth="max-w-md" closeOnBackdrop={!saving && !result}>
      {result ? (
        <div className="px-6 py-5">
          <p className="m-0 p-3 rounded-lg bg-green-50 border border-green-200 text-sm text-green-900" role="status">
            {result.message}
          </p>
          <p className="m-0 mt-4 text-sm text-gray-700">New login username to give the student:</p>
          <p className="m-0 mt-1 text-2xl font-bold tracking-widest text-gray-900">{result.username ?? result.student_number}</p>
          <p className="m-0 mt-2 text-xs text-gray-600">
            The password is unchanged. {result.previous_number} no longer logs in, and the student's open sessions were signed out.
          </p>
          <div className="flex justify-end mt-6">
            <button type="button" onClick={onClose} className="py-2.5 px-5 rounded-lg text-sm font-medium bg-tmcc text-white hover:bg-tmcc-dark">
              Done
            </button>
          </div>
        </div>
      ) : (
        <form onSubmit={save} noValidate className="px-6 py-5 space-y-4">
          <p className="m-0 text-sm text-gray-700">
            Current number: <span className="font-semibold tracking-wider">{currentNumber}</span>. The login username changes with it.
          </p>
          <StudentNumberField
            id="change-student-number"
            label="New student number *"
            prefix={prefix}
            part={part}
            onPartChange={(value) => {
              setPart(value);
              setErrors((prev) => ({ ...prev, student_number: null }));
            }}
            error={errors.student_number}
            ignore={currentNumber}
          />
          <div className="flex flex-col gap-1.5">
            <label htmlFor="change-student-number-reason" className="text-sm font-medium text-gray-600">Reason *</label>
            <textarea
              id="change-student-number-reason"
              value={reason}
              onChange={(e) => {
                setReason(e.target.value);
                setErrors((prev) => ({ ...prev, reason: null }));
              }}
              rows={3}
              maxLength={255}
              placeholder="e.g. Typed 260004 instead of 260005 on the enrollment form"
              className={`w-full py-2.5 px-4 rounded-lg border text-sm focus:outline-none focus:ring-2 focus:ring-tmcc/20 focus:border-tmcc ${errors.reason ? 'border-red-500 bg-red-50' : 'border-gray-300'}`}
              aria-invalid={!!errors.reason}
            />
            {errors.reason && <span className="text-xs text-red-600" role="alert">{errors.reason}</span>}
          </div>
          {message && <p className="m-0 p-3 rounded-lg bg-red-50 border border-red-200 text-sm text-red-800" role="alert">{message}</p>}
          <div className="flex justify-end gap-3">
            <button type="button" onClick={onClose} disabled={saving} className="py-2.5 px-5 rounded-lg text-sm font-medium bg-gray-200 text-gray-800 hover:bg-gray-300 disabled:opacity-70">
              Cancel
            </button>
            <button type="submit" disabled={saving} className="py-2.5 px-5 rounded-lg text-sm font-medium bg-tmcc text-white hover:bg-tmcc-dark disabled:opacity-70">
              {saving ? 'Changing...' : 'Change number'}
            </button>
          </div>
        </form>
      )}
    </Modal>
  );
};

export default ChangeStudentNumberDialog;
