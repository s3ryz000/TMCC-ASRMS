import React from 'react';
import { Link, Navigate, useNavigate } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { FiChevronLeft } from 'react-icons/fi';
import { staffApi } from '../../lib/api/staffApi';
import { queryKeys } from '../../lib/react-query/queryKeys';
import { staffToast } from '../../lib/notifications';
import { useAuth } from '../../contexts/AuthContext';
import CatalogForm from '../../components/staff/CatalogForm';
import { SUBJECT_FIELDS, EMPTY_SUBJECT } from '../../features/catalog/catalogForms';

const VIEW_PATH = '/staff/catalog/subjects/view';

/** Add subject (registrar only; admins are sent to the read-only list). */
const StaffNewSubjectPage = () => {
  const { role } = useAuth();
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  if (role !== 'staff') return <Navigate to={VIEW_PATH} replace />;

  const handleSubmit = async (payload) => {
    // Errors propagate to the form: 422s go next to the fields.
    await staffApi.createSubject(payload);
    staffToast.success('Subject created.');
    queryClient.invalidateQueries({ queryKey: queryKeys.staff.subjects() });
    queryClient.invalidateQueries({ queryKey: queryKeys.staff.subjectPrefixes() });
    navigate(VIEW_PATH);
  };

  return (
    <>
      <Link to="/staff/catalog/subjects" className="inline-flex items-center gap-1 mb-2 text-sm text-gray-600 no-underline hover:text-tmcc">
        <FiChevronLeft aria-hidden /> Subjects
      </Link>
      <section className="mb-6">
        <h2 className="m-0 text-2xl font-bold text-gray-800">Add subject</h2>
        <p className="mt-2 m-0 text-gray-600">
          Start the code with its CHED prefix, e.g. <span className="font-medium">TPC 11</span> or <span className="font-medium">GEC-PC</span>.
        </p>
      </section>

      <section className="max-w-2xl bg-white rounded-xl shadow-[0_4px_14px_rgba(0,0,0,0.08)] border border-gray-100">
        <CatalogForm
          idPrefix="new-subject"
          fields={SUBJECT_FIELDS}
          initialValues={EMPTY_SUBJECT}
          submitLabel="Create subject"
          onSubmit={handleSubmit}
          onCancel={() => navigate('/staff/catalog/subjects')}
          onError={(message) => staffToast.error('Could not create subject', message)}
        />
      </section>
    </>
  );
};

export default StaffNewSubjectPage;
