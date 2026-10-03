import React, { useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { FiChevronLeft, FiCopy, FiEdit2, FiPrinter } from 'react-icons/fi';
import { staffApi } from '../../lib/api/staffApi';
import { useAuth } from '../../contexts/AuthContext';
import { parseApiError } from '../../lib/api/errors';
import { queryKeys } from '../../lib/react-query/queryKeys';
import { invalidateCurriculum } from '../../lib/react-query/curriculumInvalidation';
import { staffToast } from '../../lib/notifications';
import CatalogFormModal from '../../components/staff/CatalogFormModal';
import { PROGRAM_FIELDS } from '../../features/catalog/catalogForms';
import { buildCurriculumLayout, formatUnits } from '../../features/catalog/curriculumLayout';

const BACK_PATH = '/staff/catalog/programs/view';

const thClass = 'py-2.5 px-4 text-left border-b-2 border-gray-200 bg-gray-100 font-semibold text-gray-700';
const tdClass = 'py-2.5 px-4 border-b border-gray-100 align-top';

const BackLink = () => (
  <Link to={BACK_PATH} className="inline-flex items-center gap-1 mb-2 text-sm text-gray-600 no-underline hover:text-tmcc print:hidden">
    <FiChevronLeft aria-hidden /> Back to programs
  </Link>
);

/** A program's whole curriculum, year by year and semester by semester (read-only; staff and admin). */
const StaffProgramCurriculumPage = () => {
  const { programId } = useParams();
  const { role } = useAuth();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [cloning, setCloning] = useState(false);

  // A revised curriculum for a new batch starts as a copy (#31); its editor opens next.
  const handleClone = async (payload) => {
    const result = await staffApi.cloneProgram(programId, payload);
    staffToast.success('Curriculum cloned', result?.message);
    invalidateCurriculum(queryClient, result?.program?.id);
    setCloning(false);
    navigate(`/staff/catalog/programs/${result.program.id}/curriculum/edit`);
  };

  // Same key, request and shape as Student Records' course filter (raw response, archived included).
  const programsQuery = useQuery({
    queryKey: [...queryKeys.staff.programs(), { includeArchived: true }],
    queryFn: () => staffApi.getPrograms({ include_archived: 1 }),
    staleTime: 60_000,
  });

  const curriculumQuery = useQuery({
    queryKey: queryKeys.staff.programCurriculum(programId),
    queryFn: () => staffApi.getProgramCurriculum(programId).then((d) => d?.curriculum ?? []),
    staleTime: 60_000,
  });

  const program = useMemo(
    () => (programsQuery.data?.programs ?? []).find((p) => String(p.id) === String(programId)),
    [programsQuery.data, programId],
  );
  const layout = useMemo(() => buildCurriculumLayout(curriculumQuery.data), [curriculumQuery.data]);

  if (programsQuery.isLoading) {
    return <p className="py-8 text-center text-gray-500">Loading program...</p>;
  }

  if (programsQuery.isError) {
    return (
      <>
        <BackLink />
        <div className="p-4 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm" role="alert">
          {parseApiError(programsQuery.error).message || 'Failed to load the program.'}
        </div>
      </>
    );
  }

  if (!program) {
    return (
      <>
        <BackLink />
        <p className="m-0 text-gray-700">Program not found.</p>
      </>
    );
  }

  return (
    <>
      <BackLink />
      <section className="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 className="m-0 text-2xl font-bold text-gray-800">
            {program.code} — {program.name} curriculum
          </h2>
          {program.archived && (
            <span className="inline-block mt-2 py-1 px-3 rounded-full text-xs font-medium bg-gray-200 text-gray-600">Archived program</span>
          )}
        </div>
        <div className="flex flex-wrap gap-2 print:hidden">
          {role === 'staff' && (
            <Link
              to={`/staff/catalog/programs/${programId}/curriculum/edit`}
              className="inline-flex items-center gap-1.5 py-2 px-4 rounded-lg text-sm font-medium no-underline bg-white text-tmcc border border-tmcc hover:bg-tmcc/5 focus:outline-none focus:ring-2 focus:ring-tmcc/30"
            >
              <FiEdit2 aria-hidden /> Edit curriculum
            </Link>
          )}
          {role === 'staff' && (
            <button
              type="button"
              onClick={() => setCloning(true)}
              className="inline-flex items-center gap-1.5 py-2 px-4 rounded-lg text-sm font-medium bg-white text-tmcc border border-tmcc hover:bg-tmcc/5 focus:outline-none focus:ring-2 focus:ring-tmcc/30"
            >
              <FiCopy aria-hidden /> Clone as new curriculum
            </button>
          )}
          {curriculumQuery.isSuccess && layout.subjectCount > 0 && (
            <button
              type="button"
              onClick={() => window.print()}
              className="inline-flex items-center gap-1.5 py-2 px-4 rounded-lg text-sm bg-tmcc text-white hover:bg-tmcc-dark focus:outline-none focus:ring-2 focus:ring-tmcc/30"
            >
              <FiPrinter aria-hidden /> Print
            </button>
          )}
        </div>
      </section>

      {curriculumQuery.isLoading && <p className="py-8 text-center text-gray-500">Loading curriculum...</p>}

      {curriculumQuery.isError && (
        <div className="p-4 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm" role="alert">
          {parseApiError(curriculumQuery.error).message || 'Failed to load the curriculum.'}
        </div>
      )}

      {curriculumQuery.isSuccess && layout.subjectCount === 0 && (
        <p className="m-0 text-gray-700">This program has no curriculum yet.</p>
      )}

      {curriculumQuery.isSuccess && layout.subjectCount > 0 && (
        <>
          {layout.years.map((year, yearIndex) => (
            <section
              key={year.yearLevel}
              className={`mb-8 ${yearIndex > 0 ? 'print:break-before-page' : ''}`}
              aria-labelledby={`year-${year.yearLevel}`}
            >
              <h3 id={`year-${year.yearLevel}`} className="m-0 mb-3 text-lg font-bold tracking-wide text-gray-800">
                {year.label}
              </h3>

              {year.semesters.map((semester) => (
                <div
                  key={semester.semester}
                  className="mb-5 bg-white rounded-xl shadow-[0_4px_14px_rgba(0,0,0,0.08)] border border-gray-100 overflow-hidden break-inside-avoid print:shadow-none print:border-gray-300"
                >
                  <h4 className="m-0 px-4 py-2.5 text-sm font-semibold tracking-wide text-gray-700 bg-gray-50 border-b border-gray-200">
                    {semester.label}
                  </h4>
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm border-collapse">
                      <thead>
                        <tr>
                          <th className={`${thClass} w-28`}>Subject</th>
                          <th className={thClass}>Description</th>
                          <th className={`${thClass} w-20 text-center`}>Units</th>
                          <th className={`${thClass} w-56`}>Pre-requisites</th>
                        </tr>
                      </thead>
                      <tbody>
                        {semester.subjects.map((subject) => (
                          <tr key={subject.id} className="text-gray-800">
                            <td className={`${tdClass} font-medium whitespace-nowrap`}>
                              {subject.code}
                              {subject.archived && (
                                <span
                                  className="ml-2 inline-block py-0.5 px-2 rounded-full text-[0.65rem] font-medium bg-gray-200 text-gray-600 align-middle"
                                  title="Archived: kept in the prospectus, but it can't be added to new enrollments"
                                >
                                  Archived
                                </span>
                              )}
                            </td>
                            <td className={tdClass}>{subject.title}</td>
                            <td className={`${tdClass} text-center`}>{formatUnits(subject.units)}</td>
                            <td className={tdClass}>
                              {subject.prerequisites}
                              {subject.prerequisites && subject.unresolvedPrerequisites && ', '}
                              {subject.unresolvedPrerequisites && (
                                <span className="italic text-gray-400" title="Not linked to a subject">
                                  {subject.unresolvedPrerequisites}
                                </span>
                              )}
                            </td>
                          </tr>
                        ))}
                        <tr className="font-bold text-gray-800 bg-gray-50">
                          <td className="py-2.5 px-4" />
                          <td className="py-2.5 px-4 text-right">Total</td>
                          <td className="py-2.5 px-4 text-center">{formatUnits(semester.totalUnits)}</td>
                          <td className="py-2.5 px-4" />
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              ))}
            </section>
          ))}

          <p className="m-0 text-base font-semibold text-gray-800">
            Total units: {formatUnits(layout.totalUnits)}
            <span className="ml-2 font-normal text-gray-600">
              ({layout.subjectCount} {layout.subjectCount === 1 ? 'subject' : 'subjects'})
            </span>
          </p>
        </>
      )}

      <CatalogFormModal
        isOpen={cloning}
        onClose={() => setCloning(false)}
        title={`Clone ${program.code} as a new curriculum`}
        idPrefix="clone-program"
        fields={PROGRAM_FIELDS}
        initialValues={{ code: '', name: program.name, description: program.description ?? '' }}
        submitLabel="Clone curriculum"
        onSubmit={handleClone}
        onError={(message) => staffToast.error('Could not clone the curriculum', message)}
        notice={`Every subject, term and prerequisite of ${program.code} is copied into a new program, which you can then edit. ${program.code} itself doesn't change.`}
      />
    </>
  );
};

export default StaffProgramCurriculumPage;
