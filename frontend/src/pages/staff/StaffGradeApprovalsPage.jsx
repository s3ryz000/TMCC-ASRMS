import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { FiCheck, FiRotateCcw, FiEye } from 'react-icons/fi';
import { staffApi } from '../../lib/api/staffApi';
import { parseApiError } from '../../lib/api/errors';
import { staffToast } from '../../lib/notifications';
import { queryKeys } from '../../lib/react-query/queryKeys';
import { useAuth } from '../../contexts/AuthContext';
import Modal from '../../components/ui/Modal';
import ConfirmDialog from '../../components/ui/ConfirmDialog';

const formatDate = (value) => (value ? new Date(value).toLocaleString() : '—');
const termLabel = (s) => `A.Y. ${s.academic_year}, ${String(s.semester).startsWith('2') ? '2nd' : '1st'} semester`;
const gradeLabel = (side) => {
  if (!side) return '—';
  const value = side.grade_value ?? '';
  return value ? `${value} (${side.status ?? '—'})` : (side.status ?? '—');
};

/**
 * Grade double approval (#107). Registrar staff with "Can approve grades"
 * review grades encoded by another staff member: approve them (they are then
 * saved to the student's record) or return them with remarks.
 */
const StaffGradeApprovalsPage = () => {
  const { user } = useAuth();
  const canApprove = Boolean(user?.can_approve_grades);
  const queryClient = useQueryClient();
  const [selectedId, setSelectedId] = useState(null);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [remarks, setRemarks] = useState('');
  const [remarksError, setRemarksError] = useState('');

  const { data: submissions = [], isLoading } = useQuery({
    queryKey: queryKeys.staff.gradeSubmissions('pending'),
    queryFn: () => staffApi.getGradeSubmissions({ status: 'pending' }),
    enabled: canApprove,
  });

  const { data: detail, isLoading: detailLoading } = useQuery({
    queryKey: queryKeys.staff.gradeSubmission(selectedId),
    queryFn: () => staffApi.getGradeSubmission(selectedId),
    enabled: canApprove && selectedId != null,
  });

  const close = () => {
    setSelectedId(null);
    setConfirmOpen(false);
    setRemarks('');
    setRemarksError('');
  };

  const afterDecision = (submission) => {
    queryClient.invalidateQueries({ queryKey: [...queryKeys.staff.all, 'grade-submissions'] });
    if (submission?.student_id) {
      const sid = String(submission.student_id);
      [sid, Number(sid)].forEach((id) => {
        queryClient.invalidateQueries({ queryKey: ['academicProgress', id] });
        queryClient.invalidateQueries({ queryKey: ['academicSummary', id] });
        queryClient.invalidateQueries({ queryKey: queryKeys.staff.studentAcademicRecord(id) });
      });
    }
    close();
  };

  const approveMutation = useMutation({
    mutationFn: (id) => staffApi.approveGradeSubmission(id),
    onSuccess: (data) => {
      staffToast.success('Grades approved', data?.message || 'The grades are now on the student\'s record.');
      afterDecision(detail);
    },
    onError: (err) => {
      setConfirmOpen(false);
      staffToast.error('Approve failed', parseApiError(err).message);
    },
  });

  const returnMutation = useMutation({
    mutationFn: ({ id, remarks: text }) => staffApi.returnGradeSubmission(id, { remarks: text }),
    onSuccess: () => {
      staffToast.success('Returned', 'The grades were returned to the encoder with your remarks.');
      afterDecision(detail);
    },
    onError: (err) => {
      const parsed = parseApiError(err);
      if (parsed.errors?.remarks) {
        setRemarksError(parsed.errors.remarks[0]);
      } else {
        staffToast.error('Return failed', parsed.message);
      }
    },
  });

  const handleReturn = () => {
    if (!remarks.trim()) {
      setRemarksError('Say what needs to be corrected.');
      return;
    }
    setRemarksError('');
    returnMutation.mutate({ id: selectedId, remarks: remarks.trim() });
  };

  if (!canApprove) {
    return (
      <div className="p-8 bg-white rounded-xl border border-gray-100 shadow-sm text-center">
        <h2 className="m-0 mb-2 text-lg font-semibold text-gray-800">Grade Approvals</h2>
        <p className="m-0 text-gray-600">You don&apos;t have access to grade approvals.</p>
      </div>
    );
  }

  const busy = approveMutation.isPending || returnMutation.isPending;

  return (
    <div className="space-y-6">
      <div>
        <h2 className="m-0 text-xl font-semibold text-gray-800">Grade Approvals</h2>
        <p className="m-0 mt-1 text-sm text-gray-500">
          Grades encoded by registrar staff wait here. Approved grades go on the student&apos;s record; returned grades go back to the encoder with your remarks.
        </p>
      </div>

      <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-gray-600 border-b border-gray-200">
            <tr>
              <th className="text-left font-medium py-2.5 px-4">Student</th>
              <th className="text-left font-medium py-2.5 px-4">Program</th>
              <th className="text-left font-medium py-2.5 px-4">Term</th>
              <th className="text-left font-medium py-2.5 px-4">Encoded by</th>
              <th className="text-left font-medium py-2.5 px-4">Submitted</th>
              <th className="text-center font-medium py-2.5 px-4">Grades</th>
              <th className="text-right font-medium py-2.5 px-4"><span className="sr-only">Actions</span></th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {isLoading ? (
              <tr><td colSpan="7" className="py-6 text-center text-gray-500">Loading…</td></tr>
            ) : submissions.length === 0 ? (
              <tr><td colSpan="7" className="py-6 text-center text-gray-500">No grades are waiting for approval.</td></tr>
            ) : (
              submissions.map((s) => (
                <tr key={s.id} className="hover:bg-gray-50/50">
                  <td className="py-2.5 px-4">
                    <span className="font-medium text-gray-900">{s.student_number}</span>
                    <span className="block text-gray-600">{s.student_name}</span>
                  </td>
                  <td className="py-2.5 px-4 text-gray-600">{s.program ?? '—'}</td>
                  <td className="py-2.5 px-4 text-gray-600">{termLabel(s)}</td>
                  <td className="py-2.5 px-4 text-gray-600">{s.submitted_by?.name ?? '—'}</td>
                  <td className="py-2.5 px-4 text-gray-600">{formatDate(s.submitted_at)}</td>
                  <td className="py-2.5 px-4 text-center text-gray-600">{s.item_count}</td>
                  <td className="py-2.5 px-4 text-right">
                    <button
                      type="button"
                      onClick={() => setSelectedId(s.id)}
                      className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded bg-tmcc text-white hover:bg-tmcc-dark"
                      aria-label={`Review grades for ${s.student_number}`}
                    >
                      <FiEye /> Review
                    </button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      <Modal isOpen={selectedId != null} onClose={busy ? () => {} : close} title="Review grades" titleId="grade-approval-title" maxWidth="max-w-3xl">
        <div className="p-6 space-y-5">
          {detailLoading || !detail ? (
            <p className="m-0 text-gray-500">Loading…</p>
          ) : (
            <>
              <div className="text-sm text-gray-700 space-y-0.5">
                <p className="m-0"><span className="font-semibold">{detail.student_number}</span> {detail.student_name} ({detail.program ?? '—'})</p>
                <p className="m-0">{termLabel(detail)}</p>
                <p className="m-0 text-gray-500">Encoded by {detail.submitted_by?.name ?? '—'} on {formatDate(detail.submitted_at)}</p>
              </div>

              <div className="border border-gray-200 rounded-lg overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="bg-gray-50 text-gray-600 border-b border-gray-200">
                    <tr>
                      <th className="text-left font-medium py-2 px-3">Code</th>
                      <th className="text-left font-medium py-2 px-3">Subject</th>
                      <th className="text-center font-medium py-2 px-3">Units</th>
                      <th className="text-left font-medium py-2 px-3">Current</th>
                      <th className="text-left font-medium py-2 px-3">New</th>
                      <th className="text-left font-medium py-2 px-3">Remarks</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100">
                    {detail.items.map((item) => (
                      <tr key={item.id}>
                        <td className="py-2 px-3 font-medium text-gray-900">{item.subject_code}</td>
                        <td className="py-2 px-3 text-gray-600">{item.subject_title}</td>
                        <td className="py-2 px-3 text-center text-gray-600">{item.units}</td>
                        <td className="py-2 px-3 text-gray-500">{gradeLabel(item.old)}</td>
                        <td className="py-2 px-3 font-semibold text-gray-900">{gradeLabel(item.new)}</td>
                        <td className="py-2 px-3 text-gray-600">{item.new?.remarks || '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <div>
                <label htmlFor="grade-return-remarks" className="block text-sm font-medium text-gray-700 mb-1">
                  Remarks (required to return)
                </label>
                <textarea
                  id="grade-return-remarks"
                  rows={3}
                  maxLength={500}
                  value={remarks}
                  onChange={(e) => setRemarks(e.target.value)}
                  className={`w-full py-2 px-3 rounded-lg border text-sm focus:outline-none focus:ring-2 focus:ring-tmcc/20 focus:border-tmcc ${remarksError ? 'border-red-500 bg-red-50' : 'border-gray-300'}`}
                  placeholder="What needs to be corrected"
                  aria-invalid={!!remarksError}
                />
                {remarksError && <p className="mt-1 text-xs text-red-600">{remarksError}</p>}
              </div>

              <div className="flex flex-wrap gap-3 justify-end">
                <button
                  type="button"
                  onClick={handleReturn}
                  disabled={busy}
                  className="inline-flex items-center gap-1.5 py-2.5 px-5 rounded-lg text-sm font-medium bg-amber-600 text-white hover:bg-amber-700 disabled:opacity-70"
                >
                  <FiRotateCcw /> Return to encoder
                </button>
                <button
                  type="button"
                  onClick={() => setConfirmOpen(true)}
                  disabled={busy}
                  className="inline-flex items-center gap-1.5 py-2.5 px-5 rounded-lg text-sm font-medium bg-tmcc text-white hover:bg-tmcc-dark disabled:opacity-70"
                >
                  <FiCheck /> Approve
                </button>
              </div>
            </>
          )}
        </div>
      </Modal>

      <ConfirmDialog
        isOpen={confirmOpen}
        onClose={() => setConfirmOpen(false)}
        onConfirm={() => approveMutation.mutate(selectedId)}
        title="Approve grades"
        message={detail ? `Approve ${detail.items.length} grade(s) for ${detail.student_number}? They will be saved to the student's record.` : 'Approve these grades?'}
        confirmLabel="Approve"
        variant="success"
        loading={approveMutation.isPending}
      />
    </div>
  );
};

export default StaffGradeApprovalsPage;
