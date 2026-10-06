import React from 'react';
import { Link, Navigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { FiChevronLeft } from 'react-icons/fi';
import { staffApi } from '../../lib/api/staffApi';
import { parseApiError } from '../../lib/api/errors';
import { queryKeys } from '../../lib/react-query/queryKeys';
import { useAuth } from '../../contexts/AuthContext';

const thClass = 'py-2.5 px-4 text-left border-b-2 border-gray-200 bg-gray-100 font-semibold text-gray-700';
const tdClass = 'py-2.5 px-4 border-b border-gray-100 align-top';

/**
 * ID check (#56, registrar): students whose number doesn't start with their
 * enrollment year, or isn't in the YY+4 format, each linking to the student
 * so it can be corrected with Change Student Number.
 */
const StaffIdCheckPage = () => {
  const { role } = useAuth();

  const query = useQuery({
    queryKey: queryKeys.staff.studentNumberMismatches(),
    queryFn: () => staffApi.getStudentNumberMismatches().then((d) => d?.mismatches ?? []),
    enabled: role === 'staff',
  });

  if (role !== 'staff') return <Navigate to="/staff/students" replace />;

  return (
    <>
      <Link to="/staff/students" className="inline-flex items-center gap-1 mb-2 text-sm text-gray-600 no-underline hover:text-tmcc">
        <FiChevronLeft aria-hidden /> Student Records
      </Link>
      <section className="mb-6">
        <h2 className="m-0 text-2xl font-bold text-gray-800">ID check</h2>
        <p className="mt-2 m-0 text-gray-600">
          Student numbers start with the enrollment year (an enrollment in 2026 gives 26NNNN). These don't. Open a student and use
          Change Student Number to correct it, or fix the enrollment date if that is what's wrong.
        </p>
      </section>

      {query.isLoading && <p className="py-8 text-center text-gray-500">Checking student numbers...</p>}
      {query.isError && (
        <div className="p-4 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm" role="alert">
          {parseApiError(query.error).message || 'Failed to load the ID check.'}
        </div>
      )}
      {query.isSuccess && query.data.length === 0 && (
        <p className="m-0 p-4 rounded-lg bg-green-50 border border-green-200 text-green-800 text-sm" role="status">
          Every student number matches its enrollment year.
        </p>
      )}
      {query.isSuccess && query.data.length > 0 && (
        <div className="bg-white rounded-xl shadow-[0_4px_14px_rgba(0,0,0,0.08)] border border-gray-100 overflow-x-auto">
          <table className="w-full text-sm border-collapse">
            <thead>
              <tr>
                <th className={thClass}>Student number</th>
                <th className={thClass}>Name</th>
                <th className={thClass}>Program</th>
                <th className={thClass}>Enrollment date</th>
                <th className={thClass}>Problem</th>
                <th className={thClass}><span className="sr-only">Open</span></th>
              </tr>
            </thead>
            <tbody>
              {query.data.map((row) => (
                <tr key={row.student_id} className="text-gray-800">
                  <td className={`${tdClass} font-medium tracking-wider`}>{row.student_number}</td>
                  <td className={tdClass}>{row.name}</td>
                  <td className={tdClass}>{row.program ?? '—'}</td>
                  <td className={tdClass}>{row.enrollment_date ?? '—'}</td>
                  <td className={`${tdClass} text-amber-800`}>{row.problem}</td>
                  <td className={`${tdClass} text-right`}>
                    <Link to={`/staff/students/${row.student_id}/edit`} className="text-tmcc font-medium no-underline hover:underline">
                      Open student
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
};

export default StaffIdCheckPage;
