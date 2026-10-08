import React from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { studentApi } from '../lib/api/studentApi';
import { queryKeys } from '../lib/react-query/queryKeys';
import ProfileUpdateStatusBadge from '../components/ProfileUpdateStatusBadge';

const CITIZENSHIP_OPTIONS = [
  'Filipino','American','Canadian','Japanese','Korean','Chinese','Australian',
  'British','Singaporean','Malaysian','Indian','German','French','Italian',
  'Spanish','Indonesian','Thai','Vietnamese','Other',
];

/** The editable SIS fields, filled from the student record. */
const formFromStudent = (s = {}) => ({
  contact_number: s.contact_number || '',
  address: s.address || '',
  place_of_birth: s.place_of_birth || '',
  sex: s.sex === 'Male' || s.sex === 'male' ? 'M'
     : s.sex === 'Female' || s.sex === 'female' ? 'F'
     : (s.sex || ''),
  guardian_name: s.guardian_name || '',
  citizenship: s.citizenship || '',
  elementary_school: s.elementary_school || '',
  elementary_year: s.elementary_year ?? '',
  high_school: s.high_school || '',
  high_school_year: s.high_school_year ?? '',
  previous_school: s.previous_school || '',
  previous_course: s.previous_course || '',
});

/** "2026-10-08 09:30:00" (office time) → a short local date. */
const formatDate = (value) => (value ? new Date(value.replace(' ', 'T')).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '—');

const StudentSISPage = () => {
  const queryClient = useQueryClient();
  const [profile, setProfile] = React.useState(null);
  const [loading, setLoading] = React.useState(true);
  const [error, setError] = React.useState('');
  const [saving, setSaving] = React.useState(false);
  const [saveStatus, setSaveStatus] = React.useState(null);
  const [file, setFile] = React.useState(null);
  const [fileInputKey, setFileInputKey] = React.useState(0);
  // The returned request being corrected (#88), or null for a new request.
  const [resubmitting, setResubmitting] = React.useState(null);
  const formRef = React.useRef(null);

  const student = profile?.student;

  const [form, setForm] = React.useState(formFromStudent());

  const updatesQuery = useQuery({
    queryKey: queryKeys.student.profileUpdates(),
    queryFn: studentApi.getProfileUpdates,
    select: (res) => res?.data ?? [],
    refetchOnMount: 'always',
  });
  const updates = updatesQuery.data ?? [];

  React.useEffect(() => {
    setLoading(true);
    studentApi.getProfile()
      .then((data) => {
        setProfile(data);
        setForm(formFromStudent(data?.student || {}));
      })
      .catch(() => {
        setError('Failed to load student profile.');
        setProfile(null);
      })
      .finally(() => setLoading(false));
  }, []);

  const onChange = (e) => {
    const { name, value } = e.target;
    setForm((p) => ({ ...p, [name]: value }));
  };

  const clearFile = () => {
    setFile(null);
    setFileInputKey((k) => k + 1);
  };

  /** Pre-fill the form with the returned request's values so the student can fix them. */
  const startResubmit = (update) => {
    const requested = Object.fromEntries((update.fields || []).map((f) => [f.field, f.new ?? '']));
    setForm({ ...formFromStudent(student || {}), ...requested });
    setResubmitting(update);
    setSaveStatus(null);
    clearFile();
    formRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  const cancelResubmit = () => {
    setResubmitting(null);
    setForm(formFromStudent(student || {}));
    setSaveStatus(null);
    clearFile();
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSaving(true);
    setSaveStatus(null);
    try {
      let payload;

      // A resubmission always goes as multipart (#88), with or without a new file.
      if (file || resubmitting) {
        payload = new FormData();
        Object.entries(form).forEach(([key, val]) => {
          if (key === 'elementary_year' || key === 'high_school_year') {
            payload.append(key, val === '' ? '' : Number(val));
          } else {
            payload.append(key, val);
          }
        });
        if (file) payload.append('supporting_document', file);
      } else {
        payload = {
          ...form,
          elementary_year: form.elementary_year === '' ? null : Number(form.elementary_year),
          high_school_year: form.high_school_year === '' ? null : Number(form.high_school_year),
        };
      }

      const res = resubmitting
        ? await studentApi.resubmitProfileUpdate(resubmitting.id, payload)
        : await studentApi.updateSIS(payload);

      if (res?.message === 'No changes detected.') {
        setSaveStatus({ type: 'info', message: 'No changes detected.' });
      } else {
        setSaveStatus({
          type: 'success',
          message: res?.message || 'Your changes were submitted and are pending registrar approval.'
        });
        clearFile(); // Clear file on success
        setResubmitting(null);
        queryClient.invalidateQueries({ queryKey: queryKeys.student.profileUpdates() });
      }
    } catch (err) {
      // Check for specific validation errors like missing document
      const errors = err?.response?.data?.errors;
      let msg = err?.response?.data?.message || 'Failed to submit changes.';
      
      if (errors?.supporting_document) {
        msg = errors.supporting_document[0];
      }
      
      setSaveStatus({ type: 'error', message: msg });
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <section className="sd-content">
        <div className="sd-enrollment-section">
          <h2 className="sd-section-title sd-title-red">Student Information Sheet (SIS)</h2>
          <p className="text-gray-600">Loading…</p>
        </div>
      </section>
    );
  }

  const fullName = student
    ? `${(student.last_name || '').toUpperCase()}, ${(student.first_name || '').toUpperCase()} ${student.middle_name ? student.middle_name.toUpperCase() : ''}`.trim()
    : '—';

  return (
    <section className="sd-content">
      <div className="sd-enrollment-section mb-6" id="my-update-requests">
        <h2 className="sd-section-title sd-title-red">My Update Requests</h2>
        <p className="sd-filter-hint">
          Changes you submitted and the Registrar&apos;s decision. A request returned for revision can be corrected and resubmitted.
        </p>

        {updatesQuery.isLoading ? (
          <p className="text-gray-600 text-sm">Loading…</p>
        ) : updatesQuery.isError ? (
          <p className="text-red-700 text-sm" role="alert">Could not load your update requests.</p>
        ) : updates.length === 0 ? (
          <p className="text-gray-500 text-sm">You have not submitted any profile changes yet.</p>
        ) : (
          <ul className="space-y-3 m-0 p-0 list-none">
            {updates.map((u) => (
              <li
                key={u.id}
                className={`rounded-lg border p-3 sm:p-4 ${resubmitting?.id === u.id ? 'border-orange-400 bg-orange-50/40' : 'border-gray-200 bg-white'}`}
              >
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <ProfileUpdateStatusBadge status={u.status} />
                  <span className="text-xs text-gray-500">
                    Submitted {formatDate(u.submitted_at)}
                    {u.decided_at ? ` · Decided ${formatDate(u.decided_at)}` : ''}
                  </span>
                </div>

                <ul className="mt-2 space-y-1 text-sm m-0 p-0 list-none">
                  {(u.fields || []).map((f) => (
                    <li key={f.field} className="break-words">
                      <span className="font-semibold capitalize">{f.label}:</span>{' '}
                      <span className="text-gray-500 line-through">{f.old || 'empty'}</span>{' → '}
                      <span className="text-gray-900">{f.new || 'empty'}</span>
                    </li>
                  ))}
                </ul>

                {u.reason && (
                  <p className={`mt-2 mb-0 text-sm rounded-md px-3 py-2 ${u.status === 'revision_required' ? 'bg-orange-50 text-orange-900' : 'bg-red-50 text-red-900'}`}>
                    <span className="font-semibold">{u.status === 'revision_required' ? 'Registrar\'s remarks: ' : 'Reason: '}</span>
                    {u.reason}
                  </p>
                )}

                {u.status === 'revision_required' && resubmitting?.id !== u.id && (
                  <button
                    type="button"
                    onClick={() => startResubmit(u)}
                    className="mt-3 inline-flex items-center px-3 py-1.5 text-sm font-semibold text-orange-800 bg-orange-100 border border-orange-200 rounded-md hover:bg-orange-200"
                  >
                    Correct and resubmit
                  </button>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>

      <form onSubmit={handleSubmit} ref={formRef}>
        <div className="sd-enrollment-section">
          <h2 className="sd-section-title sd-title-red">Student Information Sheet (SIS) / SIUF</h2>
          <p className="sd-filter-hint">
            Update the fields below as required by the Registrar.
          </p>

          {resubmitting && (
            <div className="mt-3 p-3 rounded-lg border border-orange-200 bg-orange-50 text-sm text-orange-900" role="status">
              <p className="m-0 font-semibold">Correcting your returned request</p>
              {resubmitting.reason && <p className="m-0 mt-1">Registrar&apos;s remarks: {resubmitting.reason}</p>}
              <p className="m-0 mt-1">
                The form shows the values you requested. Fix them, attach a new document if asked, then press Resubmit.
                {resubmitting.has_supporting_document ? ' If you attach nothing, your earlier document is kept.' : ''}
              </p>
              <button type="button" onClick={cancelResubmit} className="mt-2 text-sm font-semibold underline text-orange-900">
                Cancel correction
              </button>
            </div>
          )}

          {error && (
            <div className="mx-0 mt-3 p-3 rounded-lg border text-sm bg-red-50 border-red-200 text-red-800" role="alert">
              {error}
            </div>
          )}

          <h3 className="sd-section-title sd-title-red" style={{ fontSize: 18, marginTop: 20 }}>Personal Information</h3>

          <div className="sd-cards-row" style={{ gap: 12 }}>
            <div style={{ flex: 1 }}>
              <label className="block text-sm font-semibold mb-1">Student Name</label>
              <input
                type="text"
                value={fullName}
                className="w-full rounded-md border border-gray-200 bg-gray-50 px-3 py-2"
                readOnly
              />
            </div>
            <div style={{ width: 260 }}>
              <label className="block text-sm font-semibold mb-1">Date of Birth</label>
              <input
                type="text"
                value={student?.date_of_birth || ''}
                className="w-full rounded-md border border-gray-200 bg-gray-50 px-3 py-2"
                readOnly
              />
            </div>
          </div>

          <div className="sd-cards-row" style={{ gap: 12 }}>
            <div style={{ flex: 1 }}>
              <label className="block text-sm font-semibold mb-1">Address</label>
              <input
                name="address"
                type="text"
                value={form.address}
                onChange={onChange}
                className="w-full rounded-md border border-gray-300 px-3 py-2"
              />
            </div>
            <div style={{ width: 280 }}>
              <label className="block text-sm font-semibold mb-1">Place of Birth</label>
              <input
                name="place_of_birth"
                type="text"
                value={form.place_of_birth}
                onChange={onChange}
                className="w-full rounded-md border border-gray-300 px-3 py-2"
              />
            </div>
          </div>

          <div className="sd-cards-row" style={{ gap: 12 }}>
            <div style={{ width: 220 }}>
              <label className="block text-sm font-semibold mb-1">Sex</label>
              <select
                name="sex"
                value={form.sex}
                onChange={onChange}
                className="w-full rounded-md border border-gray-300 px-3 py-2"
              >
                <option value="">Select…</option>
                <option value="M">Male</option>
                <option value="F">Female</option>
              </select>
            </div>
            <div style={{ flex: 1 }}>
              <label className="block text-sm font-semibold mb-1">Name of Guardian</label>
              <input
                name="guardian_name"
                type="text"
                value={form.guardian_name}
                onChange={onChange}
                className="w-full rounded-md border border-gray-300 px-3 py-2"
              />
            </div>
            <div style={{ width: 260 }}>
              <label className="block text-sm font-semibold mb-1">Citizenship</label>
              <select
                name="citizenship"
                value={form.citizenship}
                onChange={onChange}
                className="w-full rounded-md border border-gray-300 px-3 py-2 bg-white"
              >
                <option value="">Select citizenship…</option>
                {CITIZENSHIP_OPTIONS.map((c) => (
                  <option key={c} value={c}>{c}</option>
                ))}
              </select>
            </div>
          </div>

          <div className="sd-cards-row" style={{ gap: 12 }}>
            <div style={{ width: 260 }}>
              <label className="block text-sm font-semibold mb-1">Contact Number</label>
              <input
                name="contact_number"
                type="text"
                value={form.contact_number}
                onChange={onChange}
                className="w-full rounded-md border border-gray-300 px-3 py-2"
              />
            </div>
            <div style={{ flex: 1 }}>
              <label className="block text-sm font-semibold mb-1">Email</label>
              <input
                type="text"
                value={student?.email || ''}
                className="w-full rounded-md border border-gray-200 bg-gray-50 px-3 py-2"
                readOnly
              />
            </div>
          </div>
        </div>

        <div className="sd-enrollment-section mt-6">
          <h3 className="sd-section-title sd-title-red" style={{ fontSize: 18 }}>Entrance Data</h3>

          <div className="sd-cards-row" style={{ gap: 12 }}>
            <div style={{ flex: 1 }}>
              <label className="block text-sm font-semibold mb-1">Elementary School</label>
              <input
                name="elementary_school"
                type="text"
                value={form.elementary_school}
                onChange={onChange}
                className="w-full rounded-md border border-gray-300 px-3 py-2"
              />
            </div>
            <div style={{ width: 160 }}>
              <label className="block text-sm font-semibold mb-1">Year</label>
              <input
                name="elementary_year"
                type="number"
                value={form.elementary_year}
                onChange={onChange}
                className="w-full rounded-md border border-gray-300 px-3 py-2"
                min={1900}
                max={2100}
              />
            </div>
          </div>

          <div className="sd-cards-row" style={{ gap: 12 }}>
            <div style={{ flex: 1 }}>
              <label className="block text-sm font-semibold mb-1">High School</label>
              <input
                name="high_school"
                type="text"
                value={form.high_school}
                onChange={onChange}
                className="w-full rounded-md border border-gray-300 px-3 py-2"
              />
            </div>
            <div style={{ width: 160 }}>
              <label className="block text-sm font-semibold mb-1">Year</label>
              <input
                name="high_school_year"
                type="number"
                value={form.high_school_year}
                onChange={onChange}
                className="w-full rounded-md border border-gray-300 px-3 py-2"
                min={1900}
                max={2100}
              />
            </div>
          </div>

          <div className="sd-cards-row" style={{ gap: 12 }}>
            <div style={{ flex: 1 }}>
              <label className="block text-sm font-semibold mb-1">Previous School</label>
              <input
                name="previous_school"
                type="text"
                value={form.previous_school}
                onChange={onChange}
                className="w-full rounded-md border border-gray-300 px-3 py-2"
              />
            </div>
            <div style={{ flex: 1 }}>
              <label className="block text-sm font-semibold mb-1">Previous Course</label>
              <input
                name="previous_course"
                type="text"
                value={form.previous_course}
                onChange={onChange}
                className="w-full rounded-md border border-gray-300 px-3 py-2"
              />
            </div>
          </div>

          <div className="sd-cards-row" style={{ marginTop: 24 }}>
            <div className="bg-gray-50 border border-gray-200 rounded-lg p-4 w-full">
              <label className="block text-sm font-semibold text-gray-800 mb-2">
                Supporting Document <span className="text-gray-500 font-normal">(Optional unless modifying personal/background info)</span>
              </label>
              <input
                key={fileInputKey}
                type="file"
                accept=".png,.jpg,.jpeg,.pdf,.docx"
                onChange={(e) => setFile(e.target.files[0] || null)}
                className="w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"
              />
              <p className="mt-2 text-xs text-gray-500">
                Required for changes to address, school information, or graduation year. Accepted files: PNG, JPG, PDF, DOCX (Max 5MB).
              </p>
            </div>
          </div>

          <div className="sd-cards-row" style={{ justifyContent: 'flex-end', marginTop: 14, alignItems: 'center', gap: 12 }}>
            {saveStatus && (
              <div 
                className={`text-sm px-4 py-2 rounded-md ${
                  saveStatus.type === 'success' ? 'bg-green-50 text-green-700 border border-green-200' :
                  saveStatus.type === 'info' ? 'bg-blue-50 text-blue-700 border border-blue-200' :
                  'bg-red-50 text-red-700 border border-red-200'
                }`}
              >
                {saveStatus.message}
              </div>
            )}
            <button
              type="submit"
              className="sd-quick-link"
              style={{ width: 220, justifyContent: 'center' }}
              disabled={saving}
            >
              {saving ? 'Saving…' : resubmitting ? 'Resubmit' : 'Save Changes'}
            </button>
          </div>
        </div>
      </form>
    </section>
  );
};

export default StudentSISPage;
