import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { FiLayers, FiPlus, FiArchive, FiRotateCcw } from 'react-icons/fi';
import { adminApi } from '../../lib/api/adminApi';
import { queryKeys } from '../../lib/react-query/queryKeys';
import { adminToast } from '../../lib/notifications';
import { parseApiError } from '../../lib/api/errors';

const inputCls = 'w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-1 focus:ring-tmcc focus:border-tmcc bg-white';

/**
 * Phase 2 (TMCC letter, 27 Oct 2026, 1A–1C): schools and their deans, which
 * school each program belongs to, and program coordinators. Admin only.
 */
const AdminSchoolsPage = () => {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({ code: '', name: '' });

  const schoolsQuery = useQuery({ queryKey: queryKeys.admin.schools(), queryFn: adminApi.getSchools });
  const data = schoolsQuery.data;
  const deans = (data?.people ?? []).filter((p) => p.role === 'dean');
  const coordinators = (data?.people ?? []).filter((p) => p.role === 'coordinator');
  const activeSchools = (data?.schools ?? []).filter((s) => !s.archived_at);

  const refresh = () => queryClient.invalidateQueries({ queryKey: queryKeys.admin.schools() });
  const onError = (title) => (err) => adminToast.error(title, parseApiError(err).message);

  const createMutation = useMutation({
    mutationFn: adminApi.createSchool,
    onSuccess: () => { adminToast.success('School created'); setForm({ code: '', name: '' }); refresh(); },
    onError: onError('Could not create the school'),
  });
  const updateMutation = useMutation({
    mutationFn: ({ id, payload }) => adminApi.updateSchool(id, payload),
    onSuccess: () => { adminToast.success('School updated'); refresh(); },
    onError: onError('Could not update the school'),
  });
  const archiveMutation = useMutation({
    mutationFn: ({ id, archived }) => (archived ? adminApi.unarchiveSchool(id) : adminApi.archiveSchool(id)),
    onSuccess: refresh,
    onError: onError('Could not change the school'),
  });
  const programMutation = useMutation({
    mutationFn: ({ programId, schoolId }) => adminApi.assignProgramSchool(programId, schoolId),
    onSuccess: () => { adminToast.success('Program updated'); refresh(); },
    onError: onError('Could not move the program'),
  });
  const coordinatorMutation = useMutation({
    mutationFn: ({ programId, userIds }) => adminApi.setProgramCoordinators(programId, userIds),
    onSuccess: () => { adminToast.success('Coordinators updated'); refresh(); },
    onError: onError('Could not update coordinators'),
  });

  const programRow = (program, school) => (
    <li key={program.id} className="flex flex-wrap items-center gap-3 py-2 border-t border-gray-100 first:border-t-0">
      <span className="font-medium text-gray-800 w-24">{program.code}</span>
      <span className="text-sm text-gray-600 flex-1 min-w-[160px]">{program.name}</span>
      <label className="text-xs text-gray-500">
        School{' '}
        <select
          aria-label={`School of ${program.code}`}
          className={`${inputCls} w-40 inline-block`}
          value={school?.id ?? ''}
          onChange={(e) => programMutation.mutate({ programId: program.id, schoolId: e.target.value ? Number(e.target.value) : null })}
        >
          <option value="">None</option>
          {activeSchools.map((s) => <option key={s.id} value={s.id}>{s.code}</option>)}
        </select>
      </label>
      <label className="text-xs text-gray-500">
        Coordinator{' '}
        <select
          aria-label={`Coordinator of ${program.code}`}
          className={`${inputCls} w-48 inline-block`}
          value={program.coordinators?.[0]?.id ?? ''}
          onChange={(e) => coordinatorMutation.mutate({ programId: program.id, userIds: e.target.value ? [Number(e.target.value)] : [] })}
        >
          <option value="">None</option>
          {coordinators.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
      </label>
    </li>
  );

  return (
    <>
      <section className="mb-6">
        <h2 className="m-0 text-2xl font-bold text-gray-800">Schools &amp; Programs</h2>
        <p className="mt-1 m-0 text-gray-600 text-sm">
          Schools and their deans, the school of each program, and program coordinators. Faculty, coordinator and dean
          accounts are created in User Management.
        </p>
      </section>

      {schoolsQuery.isError && (
        <div className="mb-4 p-4 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm" role="alert">
          Failed to load schools.
        </div>
      )}

      <form
        className="mb-6 p-4 bg-white rounded-xl border border-gray-100 shadow-sm flex flex-wrap items-end gap-3"
        onSubmit={(e) => { e.preventDefault(); createMutation.mutate(form); }}
      >
        <label className="text-sm text-gray-700">
          Code
          <input className={`${inputCls} w-32`} value={form.code} onChange={(e) => setForm((f) => ({ ...f, code: e.target.value }))} required maxLength={20} />
        </label>
        <label className="text-sm text-gray-700 flex-1 min-w-[220px]">
          School name
          <input className={inputCls} value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} required maxLength={150} />
        </label>
        <button type="submit" disabled={createMutation.isPending} className="inline-flex items-center gap-2 py-2 px-4 rounded-lg text-sm font-medium bg-tmcc text-white hover:bg-tmcc-dark disabled:opacity-60">
          <FiPlus aria-hidden="true" /> Add school
        </button>
      </form>

      {schoolsQuery.isLoading && <p className="text-gray-600">Loading…</p>}

      <div className="space-y-4">
        {(data?.schools ?? []).map((school) => (
          <section key={school.id} className={`bg-white rounded-xl border border-gray-100 shadow-sm p-4 ${school.archived_at ? 'opacity-60' : ''}`}>
            <div className="flex flex-wrap items-center gap-3">
              <FiLayers className="text-tmcc" aria-hidden="true" />
              <h3 className="m-0 text-lg font-semibold text-gray-800 flex-1">
                {school.code} — {school.name} {school.archived_at && <span className="text-xs text-gray-500">(archived)</span>}
              </h3>
              <label className="text-xs text-gray-500">
                Dean{' '}
                <select
                  aria-label={`Dean of ${school.code}`}
                  className={`${inputCls} w-52 inline-block`}
                  value={school.dean_user_id ?? ''}
                  disabled={!!school.archived_at}
                  onChange={(e) => updateMutation.mutate({ id: school.id, payload: { code: school.code, name: school.name, dean_user_id: e.target.value ? Number(e.target.value) : null } })}
                >
                  <option value="">None</option>
                  {deans.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                </select>
              </label>
              <button
                type="button"
                onClick={() => archiveMutation.mutate({ id: school.id, archived: !!school.archived_at })}
                className="inline-flex items-center gap-1.5 py-1.5 px-3 rounded-lg text-sm bg-gray-100 text-gray-700 hover:bg-gray-200"
              >
                {school.archived_at ? <><FiRotateCcw aria-hidden="true" /> Restore</> : <><FiArchive aria-hidden="true" /> Archive</>}
              </button>
            </div>
            <ul className="mt-3 mb-0 pl-0 list-none">
              {school.programs?.length ? school.programs.map((p) => programRow(p, school)) : (
                <li className="text-sm text-gray-500">No programs in this school yet.</li>
              )}
            </ul>
          </section>
        ))}

        {data?.unassigned_programs?.length > 0 && (
          <section className="bg-white rounded-xl border border-dashed border-gray-300 p-4">
            <h3 className="m-0 text-lg font-semibold text-gray-800">Programs not in a school</h3>
            <ul className="mt-3 mb-0 pl-0 list-none">{data.unassigned_programs.map((p) => programRow(p, null))}</ul>
          </section>
        )}
      </div>
    </>
  );
};

export default AdminSchoolsPage;
