import React, { useState, useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { FiSearch } from 'react-icons/fi';
import { studentApi } from '../lib/api/studentApi';
import { roadmapMatchesStatus, subjectStatusBadge } from '../features/students/studentRecord';

const ArchivedTag = () => (
  <span
    className="ml-2 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide rounded bg-amber-100 text-amber-800 align-middle"
    title="No longer offered"
  >
    Archived
  </span>
);

const StatusBadge = ({ status }) => (
  <span className={`px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${subjectStatusBadge(status)}`}>
    {status}
  </span>
);

const formatGrade = (grade) => (grade ? Number(grade).toFixed(2) : '-');

const StudentAcademicRecordsPage = () => {
  const { data: academicSummary, isLoading, error } = useQuery({
    queryKey: ['studentAcademicSummary'],
    queryFn: studentApi.getAcademicSummary,
  });

  const [searchTerm, setSearchTerm] = useState('');
  const [yearFilter, setYearFilter] = useState('');
  const [semesterFilter, setSemesterFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');

  const roadmapData = academicSummary?.curriculum?.roadmap || [];

  const filteredRoadmap = useMemo(() => {
    return roadmapData.filter(item => {
      const matchSearch =
        item.subject_code.toLowerCase().includes(searchTerm.toLowerCase()) ||
        item.subject_description.toLowerCase().includes(searchTerm.toLowerCase());

      const matchYear = yearFilter ? String(item.curriculum_year_level) === yearFilter : true;
      const matchSem = semesterFilter ? String(item.curriculum_semester) === semesterFilter : true;

      return matchSearch && matchYear && matchSem && roadmapMatchesStatus(item, statusFilter);
    });
  }, [roadmapData, searchTerm, yearFilter, semesterFilter, statusFilter]);

  if (isLoading) {
    return <div className="p-8 text-center text-gray-500">Loading academic records...</div>;
  }

  if (error) {
    return <div className="p-8 text-center text-red-500">Failed to load academic records.</div>;
  }

  const selectClass = 'flex-1 min-w-0 sm:flex-none border border-gray-300 rounded-md px-3 py-2 bg-white';

  return (
    <section className="sd-content">
      <h2 className="sd-section-title sd-title-red mb-6">Academic Records & Curriculum Roadmap</h2>

      <div className="bg-white rounded-lg shadow border border-gray-200 overflow-hidden">

        {/* Filters */}
        <div className="p-4 border-b border-gray-200 bg-gray-50 flex flex-wrap gap-3 sm:gap-4 items-center">
          <div className="w-full sm:flex-1 sm:min-w-[200px] relative">
            <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
            <input
              type="text"
              placeholder="Search subject code or description..."
              aria-label="Search subjects"
              className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-md focus:ring-red-500 focus:border-red-500"
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
            />
          </div>

          <div className="flex flex-wrap gap-2 sm:gap-4 w-full sm:w-auto">
            <select className={selectClass} aria-label="Year" value={yearFilter} onChange={(e) => setYearFilter(e.target.value)}>
              <option value="">All Years</option>
              <option value="1">1st Year</option>
              <option value="2">2nd Year</option>
              <option value="3">3rd Year</option>
              <option value="4">4th Year</option>
            </select>

            <select className={selectClass} aria-label="Semester" value={semesterFilter} onChange={(e) => setSemesterFilter(e.target.value)}>
              <option value="">All Semesters</option>
              <option value="1">1st Semester</option>
              <option value="2">2nd Semester</option>
            </select>

            <select className={`${selectClass} w-full sm:w-auto`} aria-label="Status" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
              <option value="">All Statuses</option>
              <option value="Completed">Completed</option>
              <option value="Enrolled">Currently Enrolled</option>
              <option value="Failed">Failed (Retake Required)</option>
              <option value="Blocked">Blocked (Missing Prerequisite)</option>
              <option value="Eligible">Eligible to Take / Not Taken</option>
              <option value="Archived">Archived (no longer offered)</option>
            </select>
          </div>
        </div>

        {/* Phones (#92): one card per subject, no sideways scrolling. */}
        <ul className="md:hidden m-0 p-0 list-none divide-y divide-gray-200">
          {filteredRoadmap.length > 0 ? (
            filteredRoadmap.map((item, idx) => (
              <li key={idx} className="p-4">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="m-0 font-semibold text-gray-900">
                      {item.subject_code}
                      {item.archived && <ArchivedTag />}
                    </p>
                    <p className="m-0 mt-0.5 text-sm text-gray-700 break-words">{item.subject_description}</p>
                  </div>
                  <span className="shrink-0 text-xs text-gray-500">Y{item.curriculum_year_level} S{item.curriculum_semester}</span>
                </div>
                <dl className="m-0 mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                  <dt className="text-gray-500">Units</dt>
                  <dd className="m-0 text-gray-800">{item.units}</dd>
                  <dt className="text-gray-500">Grade</dt>
                  <dd className="m-0 text-gray-800 font-medium">{formatGrade(item.grade)}</dd>
                  <dt className="text-gray-500">Prerequisite</dt>
                  <dd className="m-0 text-gray-800 break-words">{item.prerequisites || 'None'}</dd>
                </dl>
                <div className="mt-2">
                  <StatusBadge status={item.status} />
                </div>
              </li>
            ))
          ) : (
            <li className="p-6 text-center text-gray-500">No subjects match your filters.</li>
          )}
        </ul>

        {/* Table (tablets and up) */}
        <div className="hidden md:block overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200 text-sm">
            <thead className="bg-gray-100">
              <tr>
                <th className="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Year/Sem</th>
                <th className="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Code</th>
                <th className="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Description</th>
                <th className="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Units</th>
                <th className="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Prerequisite</th>
                <th className="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Grade</th>
                <th className="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Status</th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {filteredRoadmap.length > 0 ? (
                filteredRoadmap.map((item, idx) => (
                  <tr key={idx} className="hover:bg-gray-50">
                    <td className="px-6 py-4 whitespace-nowrap text-gray-600">
                      Y{item.curriculum_year_level} S{item.curriculum_semester}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap font-medium text-gray-900">
                      {item.subject_code}
                      {item.archived && <ArchivedTag />}
                    </td>
                    <td className="px-6 py-4">
                      {item.subject_description}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-gray-500">
                      {item.units}
                    </td>
                    <td className="px-6 py-4 text-gray-500">
                      {item.prerequisites || 'None'}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap font-medium">
                      {formatGrade(item.grade)}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <StatusBadge status={item.status} />
                    </td>
                  </tr>
                ))
              ) : (
                <tr>
                  <td colSpan="7" className="px-6 py-8 text-center text-gray-500">
                    No subjects match your filters.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </section>
  );
};

export default StudentAcademicRecordsPage;
