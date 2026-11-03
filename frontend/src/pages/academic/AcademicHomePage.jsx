import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { FiLogOut, FiBookOpen, FiUsers, FiLayers } from 'react-icons/fi';
import { useAuth } from '../../contexts/AuthContext';
import { academicApi } from '../../lib/api/academicApi';
import { queryKeys } from '../../lib/react-query/queryKeys';

/**
 * Phase 2: the home page for faculty, program coordinators and deans
 * (TMCC letter, 27 Oct 2026, 1C). Shows only the signed-in person's own
 * assignments; grade sheets and approvals are added in the next modules.
 */
const AcademicHomePage = () => {
  const { logout } = useAuth();
  const meQuery = useQuery({ queryKey: queryKeys.academic.me(), queryFn: academicApi.getMe });
  const me = meQuery.data;

  const card = (icon, title, body) => (
    <div className="flex items-start gap-4 p-4 rounded-xl bg-gray-50 border border-gray-100">
      <span className="flex items-center justify-center w-10 h-10 rounded-lg bg-green-100 text-tmcc shrink-0">{icon}</span>
      <div>
        <p className="m-0 text-xs font-medium text-gray-500 uppercase tracking-wider">{title}</p>
        <div className="mt-1 text-gray-800">{body}</div>
      </div>
    </div>
  );

  return (
    <main className="min-h-screen bg-gray-50">
      <header className="bg-tmcc text-white">
        <div className="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between gap-4">
          <div>
            <p className="m-0 text-sm opacity-80">ASRMS · Trece Martires City College</p>
            <h1 className="m-0 text-xl font-bold">{me ? `${me.role_label} portal` : 'Portal'}</h1>
          </div>
          <button
            type="button"
            onClick={logout}
            className="inline-flex items-center gap-2 py-2 px-3 rounded-lg text-sm bg-white/10 hover:bg-white/20"
          >
            <FiLogOut aria-hidden="true" /> Sign out
          </button>
        </div>
      </header>

      <div className="max-w-5xl mx-auto px-4 py-6">
        {meQuery.isLoading && <p className="text-gray-600">Loading…</p>}
        {meQuery.isError && (
          <div className="p-4 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm" role="alert">
            Could not load your assignments.
          </div>
        )}
        {me && (
          <>
            <h2 className="mt-0 text-2xl font-bold text-gray-800">Welcome, {me.name}</h2>
            <section className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {me.dean_of && card(<FiLayers />, 'Dean of', (
                <>
                  <p className="m-0 font-semibold">{me.dean_of.name}</p>
                  <p className="m-0 text-sm text-gray-600">
                    Programs: {me.dean_of.programs?.length ? me.dean_of.programs.map((p) => p.code).join(', ') : 'none yet'}
                  </p>
                </>
              ))}
              {me.coordinates?.length > 0 && card(<FiUsers />, 'Program coordinator of', (
                <ul className="m-0 pl-4">
                  {me.coordinates.map((p) => <li key={p.id}>{p.code} — {p.name}</li>)}
                </ul>
              ))}
              {me.faculty && card(<FiBookOpen />, 'Faculty', (
                <>
                  <p className="m-0 font-semibold">{me.faculty.school ? me.faculty.school.name : 'No school assigned yet'}</p>
                  {me.faculty.employee_no && <p className="m-0 text-sm text-gray-600">Employee no. {me.faculty.employee_no}</p>}
                </>
              ))}
              {!me.dean_of && !me.coordinates?.length && !me.faculty && (
                <p className="text-gray-600">No assignments yet. The administrator assigns your school or programs.</p>
              )}
            </section>
            <p className="mt-6 text-sm text-gray-500">
              Grade sheets, endorsements and approvals will appear here once they are enabled for your college.
            </p>
          </>
        )}
      </div>
    </main>
  );
};

export default AcademicHomePage;
