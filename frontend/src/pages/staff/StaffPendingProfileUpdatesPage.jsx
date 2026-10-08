import React, { useState, useEffect, useCallback } from 'react';
import { staffApi } from '../../lib/api/staffApi';
import { staffToast } from '../../lib/notifications';
import { useAuth } from '../../contexts/AuthContext';
import ProfileUpdateStatusBadge, { PROFILE_UPDATE_STATUSES } from '../../components/ProfileUpdateStatusBadge';
import { FiCheck, FiX, FiEye, FiDownload, FiRotateCcw } from 'react-icons/fi';

const HISTORY_LABELS = {
  revision_required: 'Returned for revision',
  resubmitted: 'Corrected and resubmitted by the student',
  approved: 'Approved',
  rejected: 'Rejected',
};

const formatDate = (value) => (value ? new Date(value.replace(' ', 'T')).toLocaleString() : '—');

/**
 * Profile update requests by status (#88). Registrar staff approve, reject or
 * return a pending request for revision; admins see the same lists read-only.
 */
const StaffPendingProfileUpdatesPage = () => {
  const { role } = useAuth();
  const canDecide = role === 'staff';
  const [status, setStatus] = useState('pending');
  const [updates, setUpdates] = useState([]);
  const [loading, setLoading] = useState(true);
  const [selectedUpdate, setSelectedUpdate] = useState(null);
  const [remarks, setRemarks] = useState('');
  const [remarksError, setRemarksError] = useState('');
  const [processing, setProcessing] = useState(false);

  const fetchUpdates = useCallback(async () => {
    setLoading(true);
    try {
      const data = await staffApi.getPendingProfileUpdates({ status });
      setUpdates(Array.isArray(data) ? data : []);
    } catch (err) {
      staffToast.error('Load failed', 'Failed to load profile update requests.');
    } finally {
      setLoading(false);
    }
  }, [status]);

  useEffect(() => {
    fetchUpdates();
  }, [fetchUpdates]);

  const closeDetails = () => {
    setSelectedUpdate(null);
    setRemarks('');
    setRemarksError('');
  };

  const decide = async (action, successTitle, successText) => {
    setProcessing(true);
    try {
      await action();
      staffToast.success(successTitle, successText);
      closeDetails();
      fetchUpdates();
    } catch (err) {
      staffToast.error(`${successTitle} failed`, err?.response?.data?.message || 'Failed to update the request.');
    } finally {
      setProcessing(false);
    }
  };

  const handleApprove = (id) => decide(
    () => staffApi.approveProfileUpdate(id),
    'Approved',
    'Profile update has been approved and applied.',
  );

  const handleReject = (id) => decide(
    () => staffApi.rejectProfileUpdate(id, { rejection_reason: remarks }),
    'Rejected',
    'Profile update has been rejected.',
  );

  const handleReturn = (id) => {
    if (!remarks.trim()) {
      setRemarksError('Write remarks telling the student what to correct.');
      return;
    }
    decide(
      () => staffApi.returnProfileUpdate(id, remarks.trim()),
      'Returned',
      'The request was returned to the student for revision.',
    );
  };

  const handleDownloadDocument = async (id, originalName) => {
    try {
      const response = await staffApi.downloadProfileUpdateDocument(id);
      const url = window.URL.createObjectURL(new Blob([response.data]));
      const link = document.createElement('a');
      link.href = url;
      link.setAttribute('download', originalName || `document_${id}`);
      document.body.appendChild(link);
      link.click();
      link.parentNode.removeChild(link);
    } catch (err) {
      staffToast.error('Download failed', 'Could not download the document.');
    }
  };

  const statusLabel = PROFILE_UPDATE_STATUSES.find((s) => s.value === status)?.label ?? status;
  const isDecided = status !== 'pending';
  const selectedIsPending = selectedUpdate?.status === 'pending';

  return (
    <section className="sd-content relative">
      <h2 className="sd-section-title">Profile Update Requests</h2>
      <p className="sd-filter-hint mb-4">
        {canDecide
          ? 'Review student-submitted profile changes before officially applying them.'
          : 'Student-submitted profile changes and the registrar\'s decisions (read-only).'}
      </p>

      <div className="flex flex-wrap gap-2 mb-5" role="tablist" aria-label="Filter by status">
        {PROFILE_UPDATE_STATUSES.map((s) => (
          <button
            key={s.value}
            type="button"
            role="tab"
            aria-selected={status === s.value}
            onClick={() => setStatus(s.value)}
            className={`px-3 py-1.5 text-sm font-medium rounded-md border ${
              status === s.value
                ? 'bg-indigo-600 text-white border-indigo-600'
                : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50'
            }`}
          >
            {s.label}
          </button>
        ))}
      </div>

      {loading && updates.length === 0 ? (
        <p className="text-gray-600">Loading...</p>
      ) : updates.length === 0 ? (
        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-8 text-center text-gray-500">
          No {statusLabel.toLowerCase()} profile updates found.
        </div>
      ) : (
        <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="bg-gray-50 border-b border-gray-100 text-gray-600">
              <tr>
                <th className="px-4 py-3 font-medium">Student Name</th>
                <th className="px-4 py-3 font-medium">Student ID</th>
                <th className="px-4 py-3 font-medium">Changed Fields</th>
                <th className="px-4 py-3 font-medium">Submitted Date</th>
                {isDecided && <th className="px-4 py-3 font-medium">Decision Date</th>}
                {isDecided && <th className="px-4 py-3 font-medium">Reason / Remarks</th>}
                <th className="px-4 py-3 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {updates.map((update) => {
                const s = update.student;
                const fullName = `${s.first_name} ${s.last_name}`.toUpperCase();
                const fields = update.changed_fields?.join(', ') || 'None';

                return (
                  <tr key={update.id} className="hover:bg-gray-50/50">
                    <td className="px-4 py-3 text-gray-800 font-medium">{fullName}</td>
                    <td className="px-4 py-3 text-gray-600">{s.student_number || s.student_id}</td>
                    <td className="px-4 py-3 text-gray-600 truncate max-w-[200px]" title={fields}>{fields}</td>
                    <td className="px-4 py-3 text-gray-600">{new Date(update.created_at).toLocaleDateString()}</td>
                    {isDecided && (
                      <td className="px-4 py-3 text-gray-600">
                        {update.reviewed_at ? new Date(update.reviewed_at).toLocaleDateString() : '—'}
                      </td>
                    )}
                    {isDecided && (
                      <td className="px-4 py-3 text-gray-600 truncate max-w-[240px]" title={update.rejection_reason || ''}>
                        {update.rejection_reason || '—'}
                      </td>
                    )}
                    <td className="px-4 py-3">
                      <button
                        onClick={() => setSelectedUpdate(update)}
                        className="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-50 border border-indigo-100 rounded-md hover:bg-indigo-100"
                      >
                        <FiEye className="w-3.5 h-3.5" /> View Details
                      </button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {selectedUpdate && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40">
          <div className="bg-white rounded-xl shadow-lg w-full max-w-2xl overflow-hidden max-h-[90vh] flex flex-col">
            <div className="px-6 py-4 border-b border-gray-100 flex justify-between items-center">
              <h3 className="text-lg font-bold text-gray-800 m-0">
                {selectedIsPending && canDecide ? 'Review Profile Changes' : 'Profile Update Request'}
              </h3>
              <button onClick={closeDetails} className="text-gray-400 hover:text-gray-600" aria-label="Close">
                <FiX className="w-5 h-5" />
              </button>
            </div>

            <div className="p-6 overflow-y-auto flex-1">
              <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                  <p className="text-sm text-gray-500 m-0">Student</p>
                  <p className="font-semibold text-gray-800">
                    {selectedUpdate.student?.first_name} {selectedUpdate.student?.last_name} ({selectedUpdate.student?.student_number})
                  </p>
                </div>
                <ProfileUpdateStatusBadge status={selectedUpdate.status} />
              </div>

              {!selectedIsPending && (
                <div className="mb-5 bg-gray-50 border border-gray-100 rounded-lg p-3 text-sm">
                  <p className="m-0 text-gray-700">
                    Decided {formatDate(selectedUpdate.reviewed_at)}
                    {selectedUpdate.reviewer?.name ? ` by ${selectedUpdate.reviewer.name}` : ''}
                  </p>
                  {selectedUpdate.rejection_reason && (
                    <p className="m-0 mt-1 text-gray-800">
                      <span className="font-semibold">
                        {selectedUpdate.status === 'revision_required' ? 'Remarks: ' : 'Reason: '}
                      </span>
                      {selectedUpdate.rejection_reason}
                    </p>
                  )}
                </div>
              )}

              {selectedUpdate.has_supporting_document && (
                <div className="mb-5">
                  <p className="text-sm text-gray-500 m-0 mb-1">Supporting Document</p>
                  <div className="flex items-center gap-3 bg-indigo-50/50 border border-indigo-100 p-3 rounded-lg">
                    <span className="text-sm font-medium text-indigo-900 flex-1 truncate" title={selectedUpdate.supporting_document_original_name}>
                      {selectedUpdate.supporting_document_original_name || 'Document Attached'}
                    </span>
                    <button
                      onClick={() => handleDownloadDocument(selectedUpdate.id, selectedUpdate.supporting_document_original_name)}
                      className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-indigo-700 bg-white border border-indigo-200 rounded hover:bg-indigo-50 transition-colors"
                    >
                      <FiDownload className="w-3.5 h-3.5" /> Download
                    </button>
                  </div>
                </div>
              )}

              <h4 className="text-md font-semibold text-gray-700 mb-3 border-b pb-2">
                {selectedIsPending ? 'Fields to Update' : 'Requested Changes'}
              </h4>

              <div className="space-y-4">
                {(selectedUpdate.changed_fields || []).map(field => (
                  <div key={field} className="grid grid-cols-2 gap-4 bg-gray-50 rounded-lg p-3 border border-gray-100">
                    <div>
                      <p className="text-xs font-semibold text-gray-500 uppercase mb-1">{field.replace('_', ' ')} (OLD)</p>
                      <p className="text-sm text-gray-700 break-words line-through decoration-red-400">
                        {selectedUpdate.old_values[field] || <span className="italic text-gray-400">Empty</span>}
                      </p>
                    </div>
                    <div>
                      <p className="text-xs font-semibold text-indigo-600 uppercase mb-1">NEW VALUE</p>
                      <p className="text-sm font-medium text-gray-900 break-words">
                        {selectedUpdate.new_values[field] || <span className="italic text-gray-400">Empty</span>}
                      </p>
                    </div>
                  </div>
                ))}
              </div>

              {(selectedUpdate.review_history || []).length > 0 && (
                <div className="mt-6">
                  <h4 className="text-md font-semibold text-gray-700 mb-2 border-b pb-2">History</h4>
                  <ul className="space-y-1 text-sm text-gray-700 m-0 p-0 list-none">
                    {selectedUpdate.review_history.map((h, i) => (
                      <li key={i}>
                        <span className="text-gray-500">{formatDate(h.at)}</span> — {HISTORY_LABELS[h.event] || h.event}
                        {h.remarks ? `: ${h.remarks}` : ''}
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {selectedIsPending && canDecide && (
                <div className="mt-6">
                  <label htmlFor="review-remarks" className="block text-sm font-medium text-gray-700 mb-1">Reason / Remarks</label>
                  <textarea
                    id="review-remarks"
                    value={remarks}
                    onChange={(e) => { setRemarks(e.target.value); setRemarksError(''); }}
                    className={`w-full rounded-md border px-3 py-2 text-sm ${remarksError ? 'border-red-400' : 'border-gray-300'}`}
                    rows="2"
                    maxLength={1000}
                    placeholder="Required to return for revision (what should the student correct?); optional when rejecting."
                  ></textarea>
                  {remarksError && <p className="text-xs text-red-600 mt-1 m-0">{remarksError}</p>}
                </div>
              )}
            </div>

            <div className="px-6 py-4 border-t border-gray-100 bg-gray-50 flex flex-wrap justify-end gap-3">
              <button
                onClick={closeDetails}
                className="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-800"
                disabled={processing}
              >
                {selectedIsPending && canDecide ? 'Cancel' : 'Close'}
              </button>
              {selectedIsPending && canDecide && (
                <>
                  <button
                    onClick={() => handleReturn(selectedUpdate.id)}
                    disabled={processing}
                    className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-orange-700 bg-orange-100 border border-orange-200 rounded-lg hover:bg-orange-200"
                  >
                    <FiRotateCcw className="w-4 h-4" /> Return for revision
                  </button>
                  <button
                    onClick={() => handleReject(selectedUpdate.id)}
                    disabled={processing}
                    className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-red-700 bg-red-100 border border-red-200 rounded-lg hover:bg-red-200"
                  >
                    <FiX className="w-4 h-4" /> Reject
                  </button>
                  <button
                    onClick={() => handleApprove(selectedUpdate.id)}
                    disabled={processing}
                    className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-white bg-indigo-600 border border-indigo-600 rounded-lg hover:bg-indigo-700"
                  >
                    <FiCheck className="w-4 h-4" /> Approve
                  </button>
                </>
              )}
            </div>
          </div>
        </div>
      )}
    </section>
  );
};

export default StaffPendingProfileUpdatesPage;
