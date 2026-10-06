import React from 'react';
import { Link, Navigate, useParams } from 'react-router-dom';
import { FiArrowLeft, FiBookOpen, FiUser } from 'react-icons/fi';
import { useAuth } from '../../contexts/AuthContext';
import { parseApiError } from '../../lib/api/errors';
import { useStudentQuery } from '../../hooks/useStudentQuery';
import { editStudentPaths, studentListPath } from '../../features/students/studentRoutes';

const cardClass =
  'flex flex-col gap-3 p-6 bg-white rounded-xl shadow-[0_4px_14px_rgba(0,0,0,0.08)] border border-gray-100 no-underline text-gray-800 hover:border-tmcc/30 hover:shadow-[0_4px_18px_rgba(0,0,0,0.1)] transition-all';

/**
 * Edit Student (#55): a choice between the student's information and their
 * grades & enrollment, in the Staff Dashboard card style. Registrar only.
 */
const StaffEditStudentHubPage = () => {
  const { id } = useParams();
  const { role } = useAuth();
  const student = useStudentQuery(role === 'staff' ? id : null);

  if (role !== 'staff') return <Navigate to={studentListPath(id)} replace />;

  const paths = editStudentPaths(id);
  const s = student.data;

  return (
    <>
      <Link to={studentListPath(id)} className="inline-flex items-center gap-2 mb-6 text-tmcc text-sm font-medium no-underline hover:text-tmcc-dark hover:underline">
        <FiArrowLeft aria-hidden /> Back to Student Records
      </Link>

      <section className="mb-8">
        <h2 className="m-0 text-2xl font-bold text-gray-800">Edit Student</h2>
        {student.isLoading && <p className="mt-2 m-0 text-gray-500">Loading student...</p>}
        {student.isError && (
          <div className="mt-3 p-4 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm" role="alert">
            {parseApiError(student.error).message || 'Failed to load the student.'}
          </div>
        )}
        {s && (
          <p className="mt-2 m-0 text-lg text-gray-800">
            {[s.first_name, s.last_name].filter(Boolean).join(' ')}
            <span className="ml-2 text-base font-semibold tracking-wider text-gray-500">{s.student_number}</span>
            {s.program?.code && <span className="ml-2 text-sm text-gray-500">· {s.program.code}</span>}
          </p>
        )}
        <p className="mt-2 m-0 text-sm text-gray-600">
          Choose what to update. Changes are saved to the student's record when you save them.
        </p>
      </section>

      {s && (
        <section className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4" aria-label="What to edit">
          <Link to={paths.information} className={cardClass}>
            <FiUser className="w-10 h-10 text-tmcc" aria-hidden />
            <div>
              <h3 className="m-0 text-base font-semibold text-gray-800">Student Information</h3>
              <p className="mt-1 m-0 text-sm text-gray-500">Personal, contact and enrollment details; change the student number</p>
            </div>
          </Link>
          <Link to={paths.grades} className={cardClass}>
            <FiBookOpen className="w-10 h-10 text-tmcc" aria-hidden />
            <div>
              <h3 className="m-0 text-base font-semibold text-gray-800">Grades &amp; Enrollment</h3>
              <p className="mt-1 m-0 text-sm text-gray-500">Program, academic record and grades, cancel an enrollment, add the next term</p>
            </div>
          </Link>
        </section>
      )}
    </>
  );
};

export default StaffEditStudentHubPage;
