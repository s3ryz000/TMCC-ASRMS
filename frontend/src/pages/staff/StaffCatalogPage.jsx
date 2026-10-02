import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { FiPlus, FiEdit2, FiArchive, FiRotateCcw, FiTrash2, FiSearch, FiChevronLeft, FiEye } from 'react-icons/fi';
import { staffApi } from '../../lib/api/staffApi';
import { parseApiError } from '../../lib/api/errors';
import { queryKeys } from '../../lib/react-query/queryKeys';
import { staffToast } from '../../lib/notifications';
import { useAuth } from '../../contexts/AuthContext';
import CatalogFormModal from '../../components/staff/CatalogFormModal';
import ConfirmDialog from '../../components/ui/ConfirmDialog';
import { SUBJECT_FIELDS, PROGRAM_FIELDS } from '../../features/catalog/catalogForms';

const plural = (n, one, many = `${one}s`) => `${n} ${n === 1 ? one : many}`;

/**
 * Everything that differs between the subject and program tables. The page
 * itself only knows about "records" with a code, a label field and usage counts.
 */
const CATALOGS = {
  subjects: {
    noun: 'subject',
    Noun: 'Subject',
    title: 'Subjects',
    landing: '/staff/catalog/subjects',
    newPath: '/staff/catalog/subjects/new',
    labelField: 'title',
    labelHeading: 'Title',
    fields: SUBJECT_FIELDS,
    queryKey: queryKeys.staff.subjects,
    // Prefix counts change with the subjects.
    extraKeys: [queryKeys.staff.subjectPrefixes()],
    list: (params) => staffApi.getSubjects(params).then((d) => d?.subjects ?? []),
    update: staffApi.updateSubject,
    remove: staffApi.deleteSubject,
    archive: staffApi.archiveSubject,
    unarchive: staffApi.unarchiveSubject,
    usageHeading: 'Used in',
    usage: (r) => [plural(r.curriculum_count ?? 0, 'curriculum entry', 'curriculum entries'), plural(r.grades_count ?? 0, 'grade')],
    reach: 'every program and transcript that uses it',
    toForm: (r) => ({ code: r.code ?? '', title: r.title ?? '', units: r.units ?? '', description: r.description ?? '' }),
  },
  programs: {
    noun: 'program',
    Noun: 'Program',
    title: 'Programs',
    landing: '/staff/catalog/programs',
    // Programs are created with their curriculum (New Curriculum, #70), not here.
    newPath: null,
    labelField: 'name',
    labelHeading: 'Name',
    fields: PROGRAM_FIELDS,
    queryKey: queryKeys.staff.programs,
    // New Student caches programs under its own key.
    extraKeys: [['programs']],
    list: (params) => staffApi.getPrograms(params).then((d) => d?.programs ?? []),
    update: staffApi.updateProgram,
    remove: staffApi.deleteProgram,
    archive: staffApi.archiveProgram,
    unarchive: staffApi.unarchiveProgram,
    usageHeading: 'Used by',
    usage: (r) => [plural(r.curriculum_count ?? 0, 'curriculum entry', 'curriculum entries'), plural(r.students_count ?? 0, 'student')],
    reach: 'every student record and curriculum that shows this program',
    toForm: (r) => ({ code: r.code ?? '', name: r.name ?? '', description: r.description ?? '' }),
  },
};

const CONFIRM_COPY = {
  archive: {
    title: (t) => `Archive ${t.noun}`,
    message: (t, r) => `Archive ${r.code}? It will be hidden from pickers for new records. Existing records, transcripts and enrollments are not changed, and you can unarchive it later.`,
    label: 'Archive',
    variant: 'warning',
    done: (t) => `${t.Noun} archived.`,
  },
  unarchive: {
    title: (t) => `Unarchive ${t.noun}`,
    message: (t, r) => `Unarchive ${r.code}? It will be offered again wherever ${t.noun}s are picked.`,
    label: 'Unarchive',
    variant: 'success',
    done: (t) => `${t.Noun} unarchived.`,
  },
  delete: {
    title: (t) => `Delete ${t.noun}`,
    message: (t, r) => `Delete ${r.code} permanently? This cannot be undone.`,
    label: 'Delete',
    variant: 'danger',
    done: (t) => `${t.Noun} deleted.`,
  },
};

const thClass = 'py-3 px-4 text-left border-b-2 border-gray-200 bg-gray-100 font-semibold text-gray-700';
const btnClass = 'inline-flex items-center gap-1.5 py-1.5 px-3 rounded-lg text-sm focus:outline-none focus:ring-2 transition-colors';

/** The subjects or programs table (type: 'subjects' | 'programs'). */
const StaffCatalogPage = ({ type }) => {
  const { role } = useAuth();
  const canEdit = role === 'staff';
  const queryClient = useQueryClient();
  const catalog = CATALOGS[type];
  const isSubjects = type === 'subjects';

  const [search, setSearch] = useState('');
  const [prefix, setPrefix] = useState('');
  const [showArchived, setShowArchived] = useState(false);
  // { row, initialValues } — initialValues is kept in state so it is built once per edit.
  const [editing, setEditing] = useState(null);
  // { action: 'archive' | 'unarchive' | 'delete', row }
  const [confirm, setConfirm] = useState(null);
  const [confirming, setConfirming] = useState(false);

  const { data: rows = [], isLoading, isError, error } = useQuery({
    // 'table' keeps this array apart from other screens that cache the raw
    // response under the same prefix (Student Records' course filter).
    queryKey: [...catalog.queryKey(), 'table', { includeArchived: showArchived }],
    queryFn: () => catalog.list(showArchived ? { include_archived: 1 } : {}),
    staleTime: 60_000,
  });

  const { data: prefixOptions = [] } = useQuery({
    queryKey: queryKeys.staff.subjectPrefixes(),
    queryFn: () => staffApi.getSubjectPrefixes().then((d) => d?.prefixes ?? []),
    staleTime: 5 * 60_000,
    enabled: isSubjects,
  });

  const visibleRows = useMemo(() => {
    const term = search.trim().toLowerCase();
    return rows.filter((r) => {
      if (prefix && r.prefix !== prefix) return false;
      if (!term) return true;
      return [r.code, r[catalog.labelField]].some((v) => String(v ?? '').toLowerCase().includes(term));
    });
  }, [rows, search, prefix, catalog.labelField]);

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: catalog.queryKey() });
    catalog.extraKeys.forEach((key) => queryClient.invalidateQueries({ queryKey: key }));
  };

  const handleSave = async (payload) => {
    // Errors propagate to the form: 422s go next to the fields.
    await catalog.update(editing.row.id, payload);
    staffToast.success(`${catalog.Noun} updated.`);
    setEditing(null);
    refresh();
  };

  const handleConfirm = async () => {
    const { action, row } = confirm;
    setConfirming(true);
    try {
      if (action === 'delete') await catalog.remove(row.id);
      else await catalog[action](row.id);
      staffToast.success(CONFIRM_COPY[action].done(catalog));
    } catch (err) {
      // A 409 explains what still uses the record.
      staffToast.error(`Could not ${action} ${row.code}`, parseApiError(err).message);
    } finally {
      setConfirming(false);
      setConfirm(null);
      refresh();
    }
  };

  const editNotice = (() => {
    if (!editing?.row.in_use) return null;
    const [first, second] = catalog.usage(editing.row);
    return `Used by ${first} and ${second} — changes apply to ${catalog.reach}.`;
  })();

  // Programs always have View (admins too); subjects only have registrar actions.
  const showActions = canEdit || !isSubjects;
  const columnCount = (isSubjects ? 6 : 4) + (showActions ? 1 : 0);
  const loadError = isError ? parseApiError(error).message || `Failed to load ${catalog.noun}s.` : null;
  const confirmCopy = confirm ? CONFIRM_COPY[confirm.action] : null;
  const filtered = search.trim() || prefix;

  return (
    <>
      <Link to={catalog.landing} className="inline-flex items-center gap-1 mb-2 text-sm text-gray-600 no-underline hover:text-tmcc">
        <FiChevronLeft aria-hidden /> {catalog.title}
      </Link>
      <section className="mb-6 flex flex-wrap items-center justify-between gap-4">
        <h2 className="m-0 text-2xl font-bold text-gray-800">{catalog.title}</h2>
        {canEdit && catalog.newPath && (
          <Link
            to={catalog.newPath}
            className={`${btnClass} py-2 px-4 no-underline bg-tmcc text-white hover:bg-tmcc-dark focus:ring-tmcc/30`}
          >
            <FiPlus /> Add {catalog.noun}
          </Link>
        )}
      </section>

      {!canEdit && (
        <div className="mb-4 p-4 rounded-lg bg-gray-50 border border-gray-200 text-gray-700 text-sm" role="status">
          Read-only: only registrar staff can change the subject and program catalogue.
        </div>
      )}

      {loadError && (
        <div className="mb-4 p-4 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm" role="alert">
          {loadError}
        </div>
      )}

      <section className="p-5 bg-white rounded-xl shadow-[0_4px_14px_rgba(0,0,0,0.08)] border border-gray-100 overflow-hidden">
        <div className="pb-4 flex flex-wrap items-center gap-4">
          <div className="relative flex-1 min-w-[200px] max-w-md">
            <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
            <input
              type="search"
              placeholder={`Search by code or ${catalog.labelField}...`}
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="w-full pl-9 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-tmcc/20 focus:border-tmcc"
              aria-label={`Search ${catalog.noun}s`}
            />
          </div>
          {isSubjects && (
            <select
              value={prefix}
              onChange={(e) => setPrefix(e.target.value)}
              className="py-2 px-3 max-w-full border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-tmcc/20 focus:border-tmcc"
              aria-label="Filter by subject code prefix"
            >
              <option value="">All prefixes</option>
              {prefixOptions.map((p) => (
                <option key={p.prefix} value={p.prefix}>
                  {p.label} ({p.subjects_count})
                </option>
              ))}
            </select>
          )}
          <label className="inline-flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
            <input
              type="checkbox"
              checked={showArchived}
              onChange={(e) => setShowArchived(e.target.checked)}
              className="w-4 h-4 accent-tmcc"
            />
            Show archived
          </label>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-sm border-collapse">
            <thead>
              <tr>
                <th className={thClass}>Code</th>
                {isSubjects && <th className={thClass}>Prefix</th>}
                <th className={thClass}>{catalog.labelHeading}</th>
                {isSubjects && <th className={thClass}>Units</th>}
                <th className={thClass}>{catalog.usageHeading}</th>
                <th className={thClass}>Status</th>
                {showActions && <th className={thClass}>Actions</th>}
              </tr>
            </thead>
            <tbody>
              {isLoading ? (
                <tr>
                  <td colSpan={columnCount} className="py-8 px-4 text-center text-gray-500">
                    Loading {catalog.noun}s...
                  </td>
                </tr>
              ) : visibleRows.length === 0 ? (
                <tr>
                  <td colSpan={columnCount} className="py-8 px-4 text-center text-gray-500">
                    {loadError ? '—' : filtered ? `No ${catalog.noun}s match your filters.` : `No ${catalog.noun}s yet.`}
                  </td>
                </tr>
              ) : (
                visibleRows.map((row) => (
                  <tr
                    key={row.id}
                    className={`border-b border-gray-100 ${row.archived ? 'bg-gray-50 text-gray-400' : 'text-gray-800 hover:bg-gray-50/80'}`}
                  >
                    <td className="py-3 px-4 font-medium whitespace-nowrap">{row.code}</td>
                    {isSubjects && <td className="py-3 px-4 whitespace-nowrap">{row.prefix ?? '—'}</td>}
                    <td className="py-3 px-4">{row[catalog.labelField]}</td>
                    {isSubjects && <td className="py-3 px-4">{row.units}</td>}
                    <td className="py-3 px-4 whitespace-nowrap">{catalog.usage(row).join(' · ')}</td>
                    <td className="py-3 px-4">
                      {row.archived ? (
                        <span className="inline-block py-1 px-3 rounded-full text-xs font-medium bg-gray-200 text-gray-600">Archived</span>
                      ) : (
                        <span className="inline-block py-1 px-3 rounded-full text-xs font-medium bg-green-100 text-green-800">Active</span>
                      )}
                    </td>
                    {showActions && (
                      <td className="py-3 px-4">
                        <div className="flex flex-wrap items-center gap-2">
                          {!isSubjects && (
                            <Link
                              to={`/staff/catalog/programs/${row.id}/curriculum`}
                              className={`${btnClass} no-underline bg-tmcc text-white hover:bg-tmcc-dark focus:ring-tmcc/30`}
                              aria-label={`View curriculum for ${row.code}`}
                            >
                              <FiEye /> View
                            </Link>
                          )}
                          {canEdit && (
                            <>
                              <button
                                type="button"
                                onClick={() => setEditing({ row, initialValues: catalog.toForm(row) })}
                                className={`${btnClass} bg-amber-600 text-white hover:bg-amber-700 focus:ring-amber-500/30`}
                                aria-label={`Edit ${row.code}`}
                              >
                                <FiEdit2 /> Edit
                              </button>
                              {row.archived ? (
                                <button
                                  type="button"
                                  onClick={() => setConfirm({ action: 'unarchive', row })}
                                  className={`${btnClass} bg-tmcc text-white hover:bg-tmcc-dark focus:ring-tmcc/30`}
                                  aria-label={`Unarchive ${row.code}`}
                                >
                                  <FiRotateCcw /> Unarchive
                                </button>
                              ) : (
                                <button
                                  type="button"
                                  onClick={() => setConfirm({ action: 'archive', row })}
                                  className={`${btnClass} bg-gray-600 text-white hover:bg-gray-700 focus:ring-gray-500/30`}
                                  aria-label={`Archive ${row.code}`}
                                >
                                  <FiArchive /> Archive
                                </button>
                              )}
                              {row.in_use ? (
                                <span className="text-xs text-gray-500">In use — archive instead</span>
                              ) : (
                                <button
                                  type="button"
                                  onClick={() => setConfirm({ action: 'delete', row })}
                                  className={`${btnClass} bg-red-600 text-white hover:bg-red-700 focus:ring-red-500/30`}
                                  aria-label={`Delete ${row.code}`}
                                >
                                  <FiTrash2 /> Delete
                                </button>
                              )}
                            </>
                          )}
                        </div>
                      </td>
                    )}
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </section>

      {canEdit && (
        <CatalogFormModal
          isOpen={!!editing}
          onClose={() => setEditing(null)}
          title={editing ? `Edit ${catalog.noun} ${editing.row.code}` : ''}
          idPrefix={`catalog-${catalog.noun}`}
          fields={catalog.fields}
          initialValues={editing?.initialValues}
          submitLabel="Save changes"
          onSubmit={handleSave}
          onError={(message) => staffToast.error(`Could not save ${catalog.noun}`, message)}
          notice={editNotice}
        />
      )}

      {canEdit && (
        <ConfirmDialog
          isOpen={!!confirm}
          onClose={() => !confirming && setConfirm(null)}
          onConfirm={handleConfirm}
          title={confirm ? confirmCopy.title(catalog) : ''}
          message={confirm ? confirmCopy.message(catalog, confirm.row) : ''}
          confirmLabel={confirmCopy?.label}
          variant={confirmCopy?.variant}
          loading={confirming}
        />
      )}
    </>
  );
};

export default StaffCatalogPage;
