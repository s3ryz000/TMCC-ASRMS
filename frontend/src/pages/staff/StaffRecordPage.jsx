import React, { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { FiArchive, FiArrowLeft, FiAward, FiBookOpen, FiDownload, FiEdit2, FiUser } from 'react-icons/fi';
import { staffApi } from '../../lib/api/staffApi';
import { parseApiError } from '../../lib/api/errors';
import { staffToast } from '../../lib/notifications';
import { queryKeys } from '../../lib/react-query/queryKeys';
import { formatDateOnly } from '../../lib/tools';
import { useAuth } from '../../contexts/AuthContext';
import { useStudentQuery } from '../../hooks/useStudentQuery';
import ArchiveModal from '../../components/ui/ArchiveModal';
import EditArchiveLocationModal from '../../components/staff/EditArchiveLocationModal';
import { RECORDS_PATH, editStudentPaths } from '../../features/students/studentRoutes';
import { awardsFrom, formatGwa, subjectStatusBadge } from '../../features/students/studentRecord';

const sectionClass = 'mb-6 bg-white rounded-xl shadow-[0_4px_14px_rgba(0,0,0,0.08)] border border-gray-100 overflow-hidden';
const editButtonClass =
  'inline-flex items-center gap-1.5 py-1.5 px-3 rounded-lg text-sm font-medium no-underline bg-amber-600 text-white hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500/30';
const thClass = 'text-left py-2.5 px-4 font-semibold text-gray-700 bg-gray-100 border-b border-gray-200 whitespace-nowrap';
const tdClass = 'py-2.5 px-4 border-b border-gray-100 align-top';

// Calendar dates ("2006-06-21") shown without a timezone shift (#77).
const showDate = (value) => (value ? formatDateOnly(value) : '—');

/** Downloads a blob response under the server's file name. */
function saveBlob(response, fallbackName) {
  const disposition = response.headers?.['content-disposition'] || '';
  const name = disposition.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i)?.[1] || fallbackName;
  const url = window.URL.createObjectURL(response.data);
  const link = document.createElement('a');
  link.href = url;
  link.download = decodeURIComponent(name);
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.URL.revokeObjectURL(url);
}

const Section = ({ id, icon: Icon, title, action, children }) => (
  <section className={sectionClass} aria-labelledby={id}>
    <div className="flex flex-wrap items-center justify-between gap-3 px-5 py-3 border-b border-gray-100 bg-gray-50">
      <div className="flex items-center gap-3">
        <span className="flex items-center justify-center w-9 h-9 rounded-lg bg-tmcc/10 text-tmcc">
          <Icon className="w-5 h-5" aria-hidden />
        </span>
        <h3 id={id} className="m-0 text-base font-semibold text-gray-800">{title}</h3>
      </div>
      {action}
    </div>
    <div className="p-5">{children}</div>
  </section>
);

const Details = ({ items }) => (
  <dl className="m-0 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-3">
    {items.map(({ label, value }) => (
      <div key={label}>
        <dt className="text-xs font-medium text-gray-500 uppercase tracking-wider">{label}</dt>
        <dd className="m-0 mt-0.5 text-sm text-gray-800 font-medium break-words">{value || '—'}</dd>
      </div>
    ))}
  </dl>
);

const Loading = ({ what }) => <p className="m-0 py-4 text-sm text-gray-500">Loading {what}...</p>;
const Failed = ({ error, what }) => (
  <p className="m-0 p-3 rounded-lg bg-red-50 border border-red-200 text-sm text-red-800" role="alert">
    {parseApiError(error).message || `Failed to load ${what}.`}
  </p>
);

/**
 * A student's full record (#57): Student Information, Subjects & Grades,
 * Documents & Awards and Archive Record, loaded from the API by the id in the
 * path. The registrar gets Edit and Archive; admins see the same page read-only.
 */
const StaffRecordPage = () => {
  const { id } = useParams();
  const { role } = useAuth();
  const isRegistrar = role === 'staff';
  const queryClient = useQueryClient();
  const [archiving, setArchiving] = useState(false);
  const [editingLocation, setEditingLocation] = useState(false);
  const [downloading, setDownloading] = useState(null);

  const student = useStudentQuery(id);
  const record = useQuery({
    queryKey: queryKeys.staff.studentAcademicRecord(id),
    queryFn: () => staffApi.getAcademicRecord(id),
    enabled: Boolean(id),
    refetchOnMount: 'always',
  });
  const documents = useQuery({
    queryKey: queryKeys.staff.studentDocuments(id),
    queryFn: () => staffApi.getStudentDocuments(id),
    enabled: Boolean(id),
    refetchOnMount: 'always',
  });

  const download = async (key, request, fallbackName) => {
    setDownloading(key);
    try {
      saveBlob(await request(), fallbackName);
    } catch (err) {
      staffToast.error('Download failed', parseApiError(err).message || 'Could not download the file.');
    } finally {
      setDownloading(null);
    }
  };

  const backLink = (
    <Link to={RECORDS_PATH} className="inline-flex items-center gap-2 mb-6 text-tmcc text-sm font-medium no-underline hover:text-tmcc-dark hover:underline">
      <FiArrowLeft aria-hidden /> Manage Records
    </Link>
  );

  if (student.isLoading) {
    return (
      <>
        {backLink}
        <Loading what="student" />
      </>
    );
  }
  if (student.isError || !student.data) {
    return (
      <>
        {backLink}
        {student.isError ? <Failed error={student.error} what="the student" /> : <p className="m-0 text-gray-700">Student not found.</p>}
      </>
    );
  }

  const s = student.data;
  const name = [s.first_name, s.middle_name, s.last_name].filter(Boolean).join(' ') || '—';
  const paths = editStudentPaths(id);
  const archive = s.archive_records;
  const summary = record.data?.summary;
  const curriculum = record.data?.curriculum;
  const awards = awardsFrom(summary);
  const docs = Array.isArray(documents.data) ? documents.data : [];

  return (
    <>
      {backLink}

      <section className="mb-6">
        <h2 className="m-0 text-2xl font-bold text-gray-800">{name}</h2>
        <p className="mt-1 m-0 text-gray-600">
          <span className="font-semibold tracking-wider">{s.student_number}</span>
          {s.program && <span> · {[s.program.code, s.program.name].filter(Boolean).join(' — ')}</span>}
        </p>
        {!isRegistrar && <p className="mt-2 m-0 text-sm text-gray-500">Read-only: changes to student records are made by the registrar.</p>}
      </section>

      {/* 1. Student Information */}
      <Section
        id="record-information"
        icon={FiUser}
        title="Student Information"
        action={isRegistrar && (
          <Link to={paths.information} className={editButtonClass}>
            <FiEdit2 aria-hidden /> Edit
          </Link>
        )}
      >
        <Details
          items={[
            { label: 'Student number', value: s.student_number },
            { label: 'Full name', value: name },
            { label: 'Sex', value: s.sex === 'M' ? 'Male' : s.sex === 'F' ? 'Female' : s.sex },
            { label: 'Date of birth', value: showDate(s.date_of_birth) },
            { label: 'Email', value: s.email },
            { label: 'Contact number', value: s.contact_number },
            { label: 'Address', value: s.address },
            { label: 'Program', value: s.program ? [s.program.code, s.program.name].filter(Boolean).join(' — ') : null },
            { label: 'Enrollment date', value: showDate(s.enrollment_date) },
            { label: 'Graduation date', value: showDate(s.graduation_date) },
          ]}
        />
        <div className="mt-6 mb-3 flex flex-wrap items-center justify-between gap-2">
          <h4 className="m-0 text-sm font-semibold text-gray-700">Archive location</h4>
          {isRegistrar && (
            <button type="button" onClick={() => setEditingLocation(true)} className={editButtonClass}>
              <FiEdit2 aria-hidden /> Edit location
            </button>
          )}
        </div>
        {archive ? (
          <Details
            items={[
              { label: 'Record type', value: archive.record_type },
              { label: 'Cabinet no.', value: archive.cabinet_no },
              { label: 'Shelf no.', value: archive.shelf_no },
              { label: 'Folder code', value: archive.folder_code },
              { label: 'Document status', value: archive.document_status },
            ]}
          />
        ) : (
          <p className="m-0 text-sm text-gray-500">No archive location recorded.</p>
        )}
      </Section>

      {/* 2. Subjects & Grades */}
      <Section
        id="record-grades"
        icon={FiBookOpen}
        title="Subjects & Grades"
        action={isRegistrar && (
          <Link to={paths.grades} className={editButtonClass}>
            <FiEdit2 aria-hidden /> Edit
          </Link>
        )}
      >
        {record.isLoading && <Loading what="grades" />}
        {record.isError && <Failed error={record.error} what="grades" />}
        {record.isSuccess && (
          <>
            <Details
              items={[
                { label: 'Overall GWA', value: formatGwa(summary?.overall_gwa) },
                { label: 'Units completed', value: curriculum ? `${curriculum.completed_units ?? 0} of ${curriculum.total_curriculum_units ?? 0}` : null },
                { label: 'Units left', value: curriculum?.units_left != null ? String(curriculum.units_left) : null },
              ]}
            />
            <div className="mt-5 overflow-x-auto border border-gray-200 rounded-lg">
              <table className="w-full text-sm border-collapse bg-white">
                <thead>
                  <tr>
                    <th className={thClass}>Code</th>
                    <th className={thClass}>Description</th>
                    <th className={thClass}>Yr/Sem</th>
                    <th className={thClass}>A.Y.</th>
                    <th className={thClass}>Units</th>
                    <th className={thClass}>Grade</th>
                    <th className={thClass}>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {(curriculum?.roadmap ?? []).length === 0 ? (
                    <tr><td colSpan={7} className="py-6 text-center text-gray-500">No curriculum subjects for this student's program.</td></tr>
                  ) : (
                    curriculum.roadmap.map((item) => (
                      <tr key={`${item.subject_code}-${item.academic_year ?? ''}-${item.semester ?? ''}`} className="text-gray-800">
                        <td className={`${tdClass} font-medium whitespace-nowrap`}>{item.subject_code}</td>
                        <td className={tdClass}>{item.subject_description}</td>
                        <td className={`${tdClass} whitespace-nowrap text-gray-600`}>Y{item.curriculum_year_level} S{item.curriculum_semester}</td>
                        <td className={`${tdClass} whitespace-nowrap text-gray-600`}>{item.academic_year || '—'}</td>
                        <td className={`${tdClass} text-gray-600`}>{item.units}</td>
                        <td className={`${tdClass} font-medium`}>{item.grade != null && item.grade !== '' ? Number(item.grade).toFixed(2) : '—'}</td>
                        <td className={`${tdClass} whitespace-nowrap`}>
                          <span className={`px-2 py-1 inline-flex text-xs font-semibold rounded-full ${subjectStatusBadge(item.status)}`}>{item.status}</span>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </>
        )}
      </Section>

      {/* 3. Documents & Awards: view and download only */}
      <Section id="record-documents" icon={FiAward} title="Documents & Awards">
        <div className="flex flex-wrap items-center justify-between gap-3 mb-5 p-4 rounded-lg border border-gray-200">
          <div>
            <p className="m-0 text-sm font-semibold text-gray-800">Official Transcript of Records</p>
            <p className="m-0 text-xs text-gray-500">Generated from the recorded grades.</p>
          </div>
          <button
            type="button"
            onClick={() => download('transcript', () => staffApi.downloadStudentTranscript(id), `OFFICIAL_TRANSCRIPT_${s.student_number}.pdf`)}
            disabled={downloading === 'transcript'}
            className="inline-flex items-center gap-1.5 py-1.5 px-3 rounded-lg text-sm font-medium bg-tmcc text-white hover:bg-tmcc-dark disabled:opacity-60"
          >
            <FiDownload aria-hidden /> {downloading === 'transcript' ? 'Downloading...' : 'Download PDF'}
          </button>
        </div>

        <h4 className="m-0 mb-3 text-sm font-semibold text-gray-700">Uploaded documents</h4>
        {documents.isLoading && <Loading what="documents" />}
        {documents.isError && <Failed error={documents.error} what="documents" />}
        {documents.isSuccess && docs.length === 0 && <p className="m-0 mb-5 text-sm text-gray-500">No documents uploaded.</p>}
        {docs.length > 0 && (
          <div className="mb-5 overflow-x-auto border border-gray-200 rounded-lg">
            <table className="w-full text-sm border-collapse bg-white">
              <thead>
                <tr>
                  <th className={thClass}>Type</th>
                  <th className={thClass}>File</th>
                  <th className={thClass}>Uploaded</th>
                  <th className={thClass}><span className="sr-only">Download</span></th>
                </tr>
              </thead>
              <tbody>
                {docs.map((doc) => (
                  <tr key={doc.id} className="text-gray-800">
                    <td className={tdClass}>{doc.document_type || '—'}{doc.description && <span className="block text-xs text-gray-500">{doc.description}</span>}</td>
                    <td className={`${tdClass} break-all`}>{doc.original_name}</td>
                    <td className={`${tdClass} whitespace-nowrap text-gray-600`}>
                      {doc.created_at ? String(doc.created_at).slice(0, 10) : '—'}
                      {doc.uploader?.name && <span className="block text-xs text-gray-500">by {doc.uploader.name}</span>}
                    </td>
                    <td className={`${tdClass} text-right`}>
                      <button
                        type="button"
                        onClick={() => download(`doc-${doc.id}`, () => staffApi.downloadStudentDocument(id, doc.id), doc.original_name || 'document')}
                        disabled={downloading === `doc-${doc.id}`}
                        className="inline-flex items-center gap-1.5 py-1.5 px-3 rounded-lg text-sm font-medium bg-white text-tmcc border border-tmcc hover:bg-tmcc/5 disabled:opacity-60"
                      >
                        <FiDownload aria-hidden /> Download
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <h4 className="m-0 mb-1 text-sm font-semibold text-gray-700">Awards</h4>
        <p className="m-0 mb-3 text-xs text-gray-500">Computed from the recorded grades; they change only when grades change.</p>
        {record.isLoading && <Loading what="awards" />}
        {record.isSuccess && (
          awards.length > 0 ? (
            <ul className="m-0 p-0 list-none space-y-2">
              {awards.map((award) => (
                <li key={award.key} className="flex flex-wrap items-center gap-2 p-3 rounded-lg bg-green-50 border border-green-200 text-sm">
                  <span className="font-semibold text-green-900">{award.name}</span>
                  <span className="text-green-800">{award.when}</span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="m-0 text-sm text-gray-600">
              No awards yet.{summary?.latin_honors?.reason ? ` Latin honors: ${summary.latin_honors.reason}` : ''}
            </p>
          )
        )}
      </Section>

      {/* 4. Archive Record */}
      <Section id="record-archive" icon={FiArchive} title="Archive Record">
        <p className="m-0 text-sm text-gray-700">
          Records where the student's paper file is kept (record type, cabinet, shelf, folder and document status). Archiving is
          confirmed in a dialog and written to the system log.
        </p>
        {isRegistrar ? (
          <button
            type="button"
            onClick={() => setArchiving(true)}
            className="mt-4 inline-flex items-center gap-1.5 py-2 px-4 rounded-lg text-sm font-medium bg-red-600 text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500/30"
          >
            <FiArchive aria-hidden /> Archive Record
          </button>
        ) : (
          <p className="m-0 mt-3 text-sm text-gray-500">Only the registrar can archive a record.</p>
        )}
      </Section>

      {isRegistrar && (
        <ArchiveModal
          isOpen={archiving}
          onClose={() => setArchiving(false)}
          student={s}
          onArchived={() => {
            queryClient.invalidateQueries({ queryKey: queryKeys.staff.studentDetail(id) });
            staffToast.success('Record archived', `${s.student_number}'s archive location was saved.`);
          }}
        />
      )}
      {isRegistrar && (
        <EditArchiveLocationModal
          isOpen={editingLocation}
          onClose={() => setEditingLocation(false)}
          studentId={s.student_id}
          archive={archive}
          onSaved={(res) => {
            queryClient.invalidateQueries({ queryKey: queryKeys.staff.studentDetail(id) });
            staffToast.success('Archive location', res?.message || 'Saved.');
          }}
        />
      )}
    </>
  );
};

export default StaffRecordPage;
