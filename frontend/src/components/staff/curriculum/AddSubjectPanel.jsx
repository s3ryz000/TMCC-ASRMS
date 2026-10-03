import React, { useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { FiX } from 'react-icons/fi';
import { staffApi } from '../../../lib/api/staffApi';
import { parseApiError } from '../../../lib/api/errors';
import { queryKeys } from '../../../lib/react-query/queryKeys';
import { joinSubjectCode, termLabel, formatUnits } from '../../../features/catalog/curriculumLayout';
import { SUBJECT_FIELDS, firstErrors, validateCatalogForm } from '../../../features/catalog/catalogForms';

const inputBase = 'w-full py-2 px-3 rounded-lg border text-sm focus:outline-none focus:ring-2 focus:ring-tmcc/20 focus:border-tmcc';
const inputClass = (error) => `${inputBase} ${error ? 'border-red-500 bg-red-50' : 'border-gray-300'}`;
const EMPTY_NEW = { prefix: '', part: '', title: '', units: 3, description: '' };
// Title, units and description follow the catalogue form; the code is built from prefix + part.
const NEW_SUBJECT_FIELDS = SUBJECT_FIELDS.filter((f) => f.name !== 'code');

/** Waits until typing pauses before searching. */
function useDebounced(value, delay = 300) {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const id = setTimeout(() => setDebounced(value), delay);
    return () => clearTimeout(id);
  }, [value, delay]);
  return debounced;
}

/**
 * Side panel to add a subject to one term (#70).
 *
 * Existing subject: GET /staff/subjects?search=… (archived ones are not
 *   returned); subjects already in this curriculum are not offered.
 * New subject: CHED prefix + the registrar's part, joined with nothing in
 *   between (joinSubjectCode), with title and units.
 *
 * onAddExisting(subject) and onAddNew({code, title, units, description}) return
 * promises; a 422 from onAddNew shows next to the fields.
 */
const AddSubjectPanel = ({ term, onClose, placedSubjectIds, takenCodes, prefixes, onAddExisting, onAddNew }) => {
  const [tab, setTab] = useState('existing');
  const [search, setSearch] = useState('');
  const debouncedSearch = useDebounced(search.trim());
  const [form, setForm] = useState(EMPTY_NEW);
  const [errors, setErrors] = useState({});
  const [message, setMessage] = useState(null);
  const [busyId, setBusyId] = useState(null);

  useEffect(() => {
    const escape = (e) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', escape);
    return () => document.removeEventListener('keydown', escape);
  }, [onClose]);

  const searchQuery = useQuery({
    queryKey: [...queryKeys.staff.subjects(), 'builder-search', debouncedSearch],
    queryFn: () => staffApi.getSubjects({ search: debouncedSearch, per_page: 25 }),
    enabled: tab === 'existing' && debouncedSearch.length > 0,
    staleTime: 30_000,
  });

  const results = useMemo(() => {
    const subjects = searchQuery.data?.subjects ?? [];
    return {
      offered: subjects.filter((s) => !placedSubjectIds.has(s.id) && !s.archived),
      alreadyPlaced: subjects.filter((s) => placedSubjectIds.has(s.id)).length,
      total: searchQuery.data?.meta?.total ?? subjects.length,
    };
  }, [searchQuery.data, placedSubjectIds]);

  const code = joinSubjectCode(form.prefix, form.part);

  const addExisting = async (subject) => {
    setBusyId(subject.id);
    setMessage(null);
    try {
      await onAddExisting(subject);
    } catch (err) {
      setMessage(parseApiError(err).message);
    } finally {
      setBusyId(null);
    }
  };

  const addNew = async (e) => {
    e.preventDefault();
    setMessage(null);
    const clientErrors = validateCatalogForm(NEW_SUBJECT_FIELDS, form);
    if (!form.prefix) clientErrors.prefix = 'Choose a prefix.';
    else if (takenCodes.has(code)) clientErrors.code = 'A subject with this code already exists.';
    setErrors(clientErrors);
    if (Object.keys(clientErrors).length > 0) return;

    setBusyId('new');
    try {
      await onAddNew({
        code,
        title: form.title.trim(),
        units: Number(form.units),
        description: form.description.trim() || null,
      });
      setForm(EMPTY_NEW);
    } catch (err) {
      const parsed = parseApiError(err);
      if (parsed.errors) setErrors(firstErrors(parsed.errors));
      else setMessage(parsed.message);
    } finally {
      setBusyId(null);
    }
  };

  const tabClass = (name) =>
    `flex-1 py-2 text-sm font-medium border-b-2 ${tab === name ? 'border-tmcc text-tmcc' : 'border-transparent text-gray-600 hover:text-gray-800'}`;

  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-black/30" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <aside
        role="dialog"
        aria-modal="true"
        aria-labelledby="add-subject-title"
        className="flex flex-col w-full max-w-md h-full bg-white shadow-xl"
      >
        <header className="flex items-start justify-between gap-3 px-5 py-4 border-b border-gray-200">
          <div>
            <h3 id="add-subject-title" className="m-0 text-lg font-bold text-gray-800">Add subject</h3>
            <p className="m-0 mt-0.5 text-sm text-gray-600">{termLabel(term.yearLevel, term.semester)}</p>
          </div>
          <button type="button" onClick={onClose} aria-label="Close" className="p-1.5 rounded-lg text-gray-500 hover:bg-gray-100">
            <FiX aria-hidden />
          </button>
        </header>

        <div className="flex px-5 border-b border-gray-200" role="tablist">
          <button type="button" role="tab" aria-selected={tab === 'existing'} className={tabClass('existing')} onClick={() => setTab('existing')}>
            Existing subject
          </button>
          <button type="button" role="tab" aria-selected={tab === 'new'} className={tabClass('new')} onClick={() => setTab('new')}>
            New subject
          </button>
        </div>

        <div className="flex-1 overflow-y-auto px-5 py-4">
          {message && (
            <div className="mb-3 p-3 rounded-lg bg-red-50 border border-red-200 text-sm text-red-800" role="alert">{message}</div>
          )}

          {tab === 'existing' && (
            <>
              <label htmlFor="builder-search" className="block mb-1 text-sm font-medium text-gray-700">Search by code or title</label>
              <input
                id="builder-search"
                type="search"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="GEC4, Purposive Communication"
                className={inputClass(false)}
                autoFocus
              />

              {debouncedSearch.length === 0 && <p className="mt-3 mb-0 text-sm text-gray-500">Type a code or part of a title.</p>}
              {searchQuery.isFetching && <p className="mt-3 mb-0 text-sm text-gray-500">Searching...</p>}
              {searchQuery.isError && (
                <p className="mt-3 mb-0 text-sm text-red-700" role="alert">{parseApiError(searchQuery.error).message}</p>
              )}

              {searchQuery.isSuccess && debouncedSearch.length > 0 && (
                <>
                  {results.offered.length === 0 && (
                    <p className="mt-3 mb-0 text-sm text-gray-600">
                      No subject to add. {results.alreadyPlaced > 0 && `${results.alreadyPlaced} matching ${results.alreadyPlaced === 1 ? 'subject is' : 'subjects are'} already in this curriculum. `}
                      Use the New subject tab to create one.
                    </p>
                  )}
                  <ul className="m-0 mt-3 p-0 list-none space-y-2">
                    {results.offered.map((subject) => (
                      <li key={subject.id} className="flex items-start justify-between gap-3 p-3 rounded-lg border border-gray-200">
                        <div className="min-w-0">
                          <p className="m-0 text-sm font-semibold text-gray-800">
                            {subject.code} <span className="font-normal text-gray-600">· {formatUnits(subject.units)} units</span>
                          </p>
                          <p className="m-0 text-sm text-gray-700">{subject.title}</p>
                          <p className="m-0 mt-0.5 text-xs text-gray-500">
                            {subject.programs?.length ? `Used in ${subject.programs.join(', ')}` : 'Not used in any program yet'}
                          </p>
                        </div>
                        <button
                          type="button"
                          onClick={() => addExisting(subject)}
                          disabled={busyId !== null}
                          className="shrink-0 py-1.5 px-3 rounded-lg text-xs font-medium bg-tmcc text-white hover:bg-tmcc-dark disabled:opacity-60"
                        >
                          {busyId === subject.id ? 'Adding...' : 'Add'}
                        </button>
                      </li>
                    ))}
                  </ul>
                  {results.total > (searchQuery.data?.subjects?.length ?? 0) && (
                    <p className="mt-2 mb-0 text-xs text-gray-500">Showing the first {searchQuery.data.subjects.length} of {results.total}; refine the search.</p>
                  )}
                </>
              )}
            </>
          )}

          {tab === 'new' && (
            <form onSubmit={addNew} noValidate className="space-y-4">
              <div className="grid grid-cols-[1fr_7rem] gap-3">
                <div>
                  <label htmlFor="new-prefix" className="block mb-1 text-sm font-medium text-gray-700">Prefix</label>
                  <select
                    id="new-prefix"
                    value={form.prefix}
                    onChange={(e) => setForm((f) => ({ ...f, prefix: e.target.value }))}
                    className={inputClass(errors.prefix)}
                    aria-invalid={!!errors.prefix}
                  >
                    <option value="">Choose a CHED prefix</option>
                    {prefixes.map((p) => (
                      <option key={p.prefix} value={p.prefix}>{p.label}</option>
                    ))}
                  </select>
                  {errors.prefix && <p className="mt-1 mb-0 text-xs text-red-600">{errors.prefix}</p>}
                </div>
                <div>
                  <label htmlFor="new-part" className="block mb-1 text-sm font-medium text-gray-700">Your part</label>
                  <input
                    id="new-part"
                    value={form.part}
                    onChange={(e) => setForm((f) => ({ ...f, part: e.target.value }))}
                    placeholder="11"
                    maxLength={12}
                    className={inputClass(errors.code)}
                  />
                </div>
              </div>
              <div>
                <p className="m-0 text-sm text-gray-700">
                  Code: <span className="font-semibold text-gray-900">{code || '—'}</span>
                </p>
                {errors.code && <p className="mt-1 mb-0 text-xs text-red-600" role="alert">{errors.code}</p>}
              </div>

              {NEW_SUBJECT_FIELDS.map((field) => {
                const id = `new-${field.name}`;
                const props = {
                  id,
                  value: form[field.name] ?? '',
                  onChange: (e) => setForm((f) => ({ ...f, [field.name]: e.target.value })),
                  className: inputClass(errors[field.name]),
                  placeholder: field.placeholder,
                  'aria-invalid': !!errors[field.name],
                };
                return (
                  <div key={field.name}>
                    <label htmlFor={id} className="block mb-1 text-sm font-medium text-gray-700">{field.label}</label>
                    {field.multiline ? (
                      <textarea {...props} rows={2} maxLength={field.maxLength} />
                    ) : (
                      <input {...props} type={field.type || 'text'} min={field.min} max={field.max} maxLength={field.maxLength} />
                    )}
                    {errors[field.name] && <p className="mt-1 mb-0 text-xs text-red-600" role="alert">{errors[field.name]}</p>}
                  </div>
                );
              })}

              <div className="flex justify-end gap-2">
                <button type="button" onClick={onClose} className="py-2 px-4 rounded-lg text-sm font-medium bg-gray-200 text-gray-800 hover:bg-gray-300">
                  Cancel
                </button>
                <button type="submit" disabled={busyId !== null} className="py-2 px-4 rounded-lg text-sm font-medium bg-tmcc text-white hover:bg-tmcc-dark disabled:opacity-60">
                  {busyId === 'new' ? 'Adding...' : 'Add new subject'}
                </button>
              </div>
            </form>
          )}
        </div>
      </aside>
    </div>
  );
};

export default AddSubjectPanel;
