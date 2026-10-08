import React, { useEffect, useMemo, useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { FiChevronLeft, FiChevronRight, FiSearch, FiX } from 'react-icons/fi';
import { parseApiError } from '../../lib/api/errors';

export const LOG_ROLES = [
  { value: 'admin', label: 'Admin' },
  { value: 'staff', label: 'Registrar staff' },
  { value: 'student', label: 'Student' },
  { value: 'guest', label: 'Guest (failed login)' },
  { value: 'system', label: 'System' },
];

const PER_PAGE_OPTIONS = [10, 25, 50, 100];
const SEARCH_DEBOUNCE_MS = 400;

const inputClass = 'w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800';

/**
 * The system log as a filtered, server-paged table (#94), newest first, with
 * names and Manila time. Used by the admin System Logs page (with the user
 * filter and export) and the registrar's read-only My Activity page.
 *
 * Props: queryKey(params) and fetchPage(params) load one page; users (or
 * null) fills the user filter; actions(params) renders buttons that need
 * the current filters (export, print).
 */
const ActivityLogView = ({ queryKey, fetchPage, users = null, actions = null, emptyText = 'No log entries found.' }) => {
  const [userId, setUserId] = useState('');
  const [role, setRole] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [q, setQ] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [perPage, setPerPage] = useState(25);
  const [page, setPage] = useState(1);

  useEffect(() => {
    const t = setTimeout(() => setQ(searchInput.trim()), SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(t);
  }, [searchInput]);

  // The filters as the API takes them; empty ones are left out.
  const filters = useMemo(() => Object.fromEntries(Object.entries({
    user_id: userId, role, q, date_from: dateFrom, date_to: dateTo,
  }).filter(([, v]) => v !== '')), [userId, role, q, dateFrom, dateTo]);

  useEffect(() => {
    setPage(1);
  }, [filters, perPage]);

  const datesInvalid = dateFrom !== '' && dateTo !== '' && dateTo < dateFrom;
  const params = { ...filters, page, per_page: perPage };

  const listQuery = useQuery({
    queryKey: queryKey(params),
    queryFn: () => fetchPage(params),
    placeholderData: keepPreviousData,
    enabled: !datesInvalid,
  });
  const { data, isLoading, isFetching, isError, error } = listQuery;

  const rows = data?.data ?? [];
  const total = data?.total ?? 0;
  const lastPage = data?.last_page ?? 1;
  const from = data?.from ?? 0;
  const to = data?.to ?? 0;
  const hasFilters = Object.keys(filters).length > 0;

  useEffect(() => {
    if (lastPage >= 1 && page > lastPage) setPage(lastPage);
  }, [lastPage, page]);

  const clearFilters = () => {
    setUserId('');
    setRole('');
    setSearchInput('');
    setQ('');
    setDateFrom('');
    setDateTo('');
  };

  return (
    <>
      <section className="mb-4 bg-white rounded-xl border border-gray-100 shadow-sm p-4">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6 items-end">
          {users && (
            <label className="block text-xs font-semibold text-gray-600 lg:col-span-1">
              User
              <select value={userId} onChange={(e) => setUserId(e.target.value)} className={`${inputClass} mt-1`}>
                <option value="">All users</option>
                {users.map((u) => (
                  <option key={u.id} value={u.id}>{u.name} ({u.username})</option>
                ))}
              </select>
            </label>
          )}
          <label className="block text-xs font-semibold text-gray-600">
            Role
            <select value={role} onChange={(e) => setRole(e.target.value)} className={`${inputClass} mt-1`}>
              <option value="">All roles</option>
              {LOG_ROLES.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
            </select>
          </label>
          <label className={`block text-xs font-semibold text-gray-600 ${users ? 'lg:col-span-2' : 'lg:col-span-3'}`}>
            Action contains
            <span className="relative block mt-1">
              <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden />
              <input
                type="search"
                value={searchInput}
                onChange={(e) => setSearchInput(e.target.value)}
                maxLength={100}
                placeholder="e.g. Login, grade, settings"
                className={`${inputClass} pl-9`}
              />
            </span>
          </label>
          <label className="block text-xs font-semibold text-gray-600">
            From
            <input type="date" value={dateFrom} max={dateTo || undefined} onChange={(e) => setDateFrom(e.target.value)} className={`${inputClass} mt-1`} />
          </label>
          <label className="block text-xs font-semibold text-gray-600">
            To
            <input type="date" value={dateTo} min={dateFrom || undefined} onChange={(e) => setDateTo(e.target.value)} className={`${inputClass} mt-1`} />
          </label>
        </div>
        <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
          <div className="text-xs text-gray-500">
            Times are Manila time. Newest first.
            {datesInvalid && <span className="ml-2 text-red-600 font-medium">The end date must be on or after the start date.</span>}
          </div>
          <div className="flex flex-wrap items-center gap-2">
            {hasFilters && (
              <button type="button" onClick={clearFilters} className="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-gray-900">
                <FiX aria-hidden /> Clear filters
              </button>
            )}
            {actions && actions(filters, datesInvalid)}
          </div>
        </div>
      </section>

      <section className="bg-white rounded-xl shadow-[0_4px_14px_rgba(0,0,0,0.08)] border border-gray-100 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm border-collapse">
            <thead>
              <tr className="bg-gray-100 text-gray-700 text-left">
                <th className="py-3 px-4 border-b-2 border-gray-200 font-semibold whitespace-nowrap">Date &amp; Time</th>
                <th className="py-3 px-4 border-b-2 border-gray-200 font-semibold">User</th>
                <th className="py-3 px-4 border-b-2 border-gray-200 font-semibold">Role</th>
                <th className="py-3 px-4 border-b-2 border-gray-200 font-semibold">Action</th>
                <th className="py-3 px-4 border-b-2 border-gray-200 font-semibold whitespace-nowrap">Log ID</th>
              </tr>
            </thead>
            <tbody>
              {isLoading && !data ? (
                <tr><td colSpan={5} className="py-10 px-4 text-center text-gray-500">Loading logs…</td></tr>
              ) : isError ? (
                <tr><td colSpan={5} className="py-8 px-4 text-center text-red-700" role="alert">{parseApiError(error).message}</td></tr>
              ) : rows.length > 0 ? (
                rows.map((r) => (
                  <tr key={r.log_id} className="border-b border-gray-100 hover:bg-gray-50/80 align-top">
                    <td className="py-3 px-4 text-gray-700 whitespace-nowrap">{r.logged_at_label}</td>
                    <td className="py-3 px-4 text-gray-800">
                      {r.user_name ? (
                        <>
                          <span className="block font-medium">{r.user_name}</span>
                          <span className="block text-xs text-gray-500">{r.username}</span>
                        </>
                      ) : (
                        <span className="text-gray-400">—</span>
                      )}
                    </td>
                    <td className="py-3 px-4 text-gray-700 capitalize">{r.role}</td>
                    <td className="py-3 px-4 text-gray-800 break-words min-w-[16rem]">{r.action}</td>
                    <td className="py-3 px-4 text-gray-500 tabular-nums">{r.log_id}</td>
                  </tr>
                ))
              ) : (
                <tr><td colSpan={5} className="py-8 px-4 text-center text-gray-500 italic">{hasFilters ? 'No log entries match these filters.' : emptyText}</td></tr>
              )}
            </tbody>
          </table>
        </div>

        <div className="px-4 sm:px-6 py-3 border-t border-gray-100 text-sm text-gray-500 flex flex-wrap items-center justify-between gap-3">
          <span>
            {total > 0 ? `Showing ${from} to ${to} of ${total} entries` : 'No entries'}
            {isFetching && data ? <span className="ml-1 text-gray-400">(updating…)</span> : null}
          </span>
          <div className="flex items-center gap-2">
            <label className="flex items-center gap-1">
              Show
              <select value={perPage} onChange={(e) => setPerPage(Number(e.target.value))} className="rounded-md border border-gray-300 px-2 py-1 text-sm">
                {PER_PAGE_OPTIONS.map((n) => <option key={n} value={n}>{n}</option>)}
              </select>
            </label>
            <button
              type="button"
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={page <= 1}
              className="inline-flex items-center py-1.5 px-2 rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
              aria-label="Previous page"
            >
              <FiChevronLeft className="w-4 h-4" />
            </button>
            <span className="text-gray-700 tabular-nums">Page {data?.current_page ?? page} of {lastPage}</span>
            <button
              type="button"
              onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
              disabled={page >= lastPage}
              className="inline-flex items-center py-1.5 px-2 rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
              aria-label="Next page"
            >
              <FiChevronRight className="w-4 h-4" />
            </button>
          </div>
        </div>
      </section>
    </>
  );
};

export default ActivityLogView;
