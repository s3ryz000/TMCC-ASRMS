import React, { useState, useEffect, useCallback } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import {
  FiUserPlus,
  FiSearch,
  FiEye,
  FiEdit2,
  FiChevronUp,
  FiChevronDown,
  FiChevronLeft,
  FiChevronRight,
} from 'react-icons/fi';
import CreateUserModal from '../../components/admin/CreateUserModal';
import EditUserModal from '../../components/admin/EditUserModal';
import ViewUserModal from '../../components/admin/ViewUserModal';
import { parseApiError } from '../../lib/api/errors';
import { queryKeys } from '../../lib/react-query/queryKeys';
import { useAdminUsersListQuery } from '../../hooks/useAdminUsersListQuery';
import {
  ADMIN_USERS_DEFAULT_PER_PAGE,
  ADMIN_USERS_PER_PAGE_OPTIONS,
  ADMIN_USERS_SEARCH_DEBOUNCE_MS,
} from '../../features/admin/constants';

const SORTABLE = ['name', 'username', 'role', 'status'];

const AdminUserManagementPage = () => {
  const queryClient = useQueryClient();
  const [searchInput, setSearchInput] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [filterRole, setFilterRole] = useState('');
  const [sortKey, setSortKey] = useState('name');
  const [sortDir, setSortDir] = useState('asc');
  const [entries, setEntries] = useState(ADMIN_USERS_DEFAULT_PER_PAGE);
  const [page, setPage] = useState(1);
  const [createOpen, setCreateOpen] = useState(false);
  const [editUser, setEditUser] = useState(null);
  const [viewUser, setViewUser] = useState(null);

  useEffect(() => {
    const t = setTimeout(() => setDebouncedSearch(searchInput.trim()), ADMIN_USERS_SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(t);
  }, [searchInput]);

  useEffect(() => {
    setPage(1);
  }, [debouncedSearch, filterRole, entries, sortKey, sortDir]);

  const listQuery = useAdminUsersListQuery({
    page,
    perPage: entries,
    search: debouncedSearch,
    role: filterRole,
    sort: sortKey,
    dir: sortDir,
  });

  const {
    rows,
    total,
    lastPage,
    from,
    to,
    currentPage,
    isLoading,
    isFetching,
    isError,
    error,
  } = listQuery;

  const listInitialLoading = isLoading && !listQuery.data;
  const listUpdating = isFetching && !listInitialLoading;
  const listErrorMsg = isError ? parseApiError(error).message || 'Failed to load users.' : null;

  useEffect(() => {
    if (lastPage >= 1 && page > lastPage) {
      setPage(lastPage);
    }
  }, [lastPage, page]);

  const invalidateUsers = useCallback(() => {
    queryClient.invalidateQueries({ queryKey: queryKeys.admin.all });
  }, [queryClient]);

  const toggleSort = (key) => {
    if (!SORTABLE.includes(key)) return;
    if (key === sortKey) setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'));
    else {
      setSortKey(key);
      setSortDir('asc');
    }
  };

  const SortableTh = ({ label, sortKey: sk }) => (
    <th className="py-3 px-4 text-left border-b-2 border-gray-200 bg-gray-100 font-semibold text-gray-700">
      <button
        type="button"
        className="flex items-center gap-1 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-tmcc/30 rounded"
        onClick={() => toggleSort(sk)}
      >
        {label}
        {sortKey === sk ? (
          sortDir === 'asc' ? (
            <FiChevronUp className="w-4 h-4" />
          ) : (
            <FiChevronDown className="w-4 h-4" />
          )
        ) : (
          <span className="w-4 h-4 inline-block opacity-40">
            <FiChevronUp className="w-3 h-3" />
          </span>
        )}
      </button>
    </th>
  );

  const goPrev = useCallback(() => setPage((p) => Math.max(1, p - 1)), []);
  const goNext = useCallback(() => setPage((p) => Math.min(lastPage, p + 1)), [lastPage]);

  const roleBadge = (role) => {
    const c =
      { admin: 'bg-green-100 text-tmcc', staff: 'bg-amber-100 text-amber-800', student: 'bg-blue-100 text-blue-800' }[
        role
      ] || 'bg-gray-100 text-gray-800';
    return <span className={`inline-block py-1 px-2.5 rounded-full text-xs font-medium ${c}`}>{role}</span>;
  };

  return (
    <>
      <section className="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
          <h2 className="m-0 text-2xl font-bold text-gray-800">User Management</h2>
          <p className="mt-1 m-0 text-gray-600 text-sm">Create, edit, and manage staff and admin accounts.</p>
        </div>
        <button
          type="button"
          onClick={() => setCreateOpen(true)}
          className="inline-flex items-center gap-2 py-2.5 px-4 rounded-lg text-sm font-medium bg-tmcc text-white hover:bg-tmcc-dark shadow-sm"
        >
          <FiUserPlus /> Add User
        </button>
      </section>

      <section className="bg-white rounded-xl shadow-[0_4px_14px_rgba(0,0,0,0.08)] border border-gray-100 overflow-hidden">
        <div className="p-6 border-b border-gray-100">
          <div className="flex flex-wrap items-center gap-4">
            <div className="relative flex-1 min-w-[200px] max-w-md">
              <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
              <input
                type="search"
                placeholder="Search by name, username, or email..."
                value={searchInput}
                onChange={(e) => setSearchInput(e.target.value)}
                className="w-full pl-9 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-tmcc/20 focus:border-tmcc"
                aria-label="Search users"
              />
            </div>
            <select
              value={filterRole}
              onChange={(e) => setFilterRole(e.target.value)}
              className="py-2 px-3 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-tmcc/20 focus:border-tmcc"
              aria-label="Filter by role"
            >
              <option value="">All roles</option>
              <option value="admin">Admin</option>
              <option value="staff">Staff</option>
            </select>
            <div className="flex items-center gap-2 text-sm text-gray-600">
              <span>Show</span>
              <select
                value={entries}
                onChange={(e) => setEntries(Number(e.target.value))}
                className="py-1.5 px-2 border border-gray-300 rounded text-sm"
              >
                {ADMIN_USERS_PER_PAGE_OPTIONS.map((n) => (
                  <option key={n} value={n}>
                    {n}
                  </option>
                ))}
              </select>
              <span>entries</span>
            </div>
          </div>
        </div>

        {listErrorMsg && (
          <div className="px-6 pt-4">
            <div className="p-4 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm" role="alert">
              {listErrorMsg}
            </div>
          </div>
        )}

        <div className="overflow-x-auto">
          <table className="w-full text-sm border-collapse">
            <thead>
              <tr>
                <SortableTh label="Name" sortKey="name" />
                <SortableTh label="Username" sortKey="username" />
                <SortableTh label="Role" sortKey="role" />
                <SortableTh label="Status" sortKey="status" />
                <th className="py-3 px-4 text-left border-b-2 border-gray-200 bg-gray-100 font-semibold text-gray-700">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody>
              {listInitialLoading ? (
                <tr>
                  <td colSpan={5} className="py-10 px-4 text-center text-gray-500">
                    Loading users…
                  </td>
                </tr>
              ) : rows.length > 0 ? (
                rows.map((u) => (
                  <tr key={u.id} className="border-b border-gray-100 hover:bg-gray-50/80">
                    <td className="py-3 px-4 text-gray-800">{u.name}</td>
                    <td className="py-3 px-4 text-gray-700">{u.username}</td>
                    <td className="py-3 px-4">{roleBadge(u.role)}</td>
                    <td className="py-3 px-4">
                      <span
                        className={`inline-block py-1 px-2.5 rounded-full text-xs font-medium ${
                          u.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'
                        }`}
                      >
                        {u.status}
                      </span>
                    </td>
                    <td className="py-3 px-4">
                      <div className="flex gap-2">
                        <button
                          type="button"
                          onClick={() => setViewUser(u)}
                          className="inline-flex items-center gap-1.5 py-1.5 px-3 rounded-lg text-sm bg-tmcc text-white hover:bg-tmcc-dark"
                          aria-label={`View ${u.name}`}
                        >
                          <FiEye /> View
                        </button>
                        <button
                          type="button"
                          onClick={() => setEditUser(u)}
                          className="inline-flex items-center gap-1.5 py-1.5 px-3 rounded-lg text-sm bg-amber-600 text-white hover:bg-amber-700"
                          aria-label={`Edit ${u.name}`}
                        >
                          <FiEdit2 /> Edit
                        </button>
                      </div>
                    </td>
                  </tr>
                ))
              ) : (
                <tr>
                  <td colSpan={5} className="py-8 px-4 text-center text-gray-500 italic">
                    No users found.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>

        {total > 0 && (
          <div className="px-6 py-3 border-t border-gray-100 text-sm text-gray-500 flex flex-wrap items-center justify-between gap-3">
            <span>
              Showing {from || 0} to {to || 0} of {total} entries
              {listUpdating ? <span className="ml-1 text-gray-400">(updating…)</span> : null}
            </span>
            <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={goPrev}
                disabled={page <= 1 || listInitialLoading}
                className="inline-flex items-center gap-1 py-1.5 px-2 rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                aria-label="Previous page"
              >
                <FiChevronLeft className="w-4 h-4" />
              </button>
              <span className="text-gray-700 tabular-nums">
                Page {currentPage} of {lastPage}
              </span>
              <button
                type="button"
                onClick={goNext}
                disabled={page >= lastPage || listInitialLoading}
                className="inline-flex items-center gap-1 py-1.5 px-2 rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                aria-label="Next page"
              >
                <FiChevronRight className="w-4 h-4" />
              </button>
            </div>
          </div>
        )}
      </section>

      <CreateUserModal
        isOpen={createOpen}
        onClose={() => setCreateOpen(false)}
        onSuccess={invalidateUsers}
      />
      <EditUserModal isOpen={!!editUser} onClose={() => setEditUser(null)} user={editUser} onSuccess={invalidateUsers} />
      <ViewUserModal
        isOpen={!!viewUser}
        onClose={() => setViewUser(null)}
        user={viewUser}
        onEdit={(u) => {
          setViewUser(null);
          setEditUser(u);
        }}
      />
    </>
  );
};

export default AdminUserManagementPage;
