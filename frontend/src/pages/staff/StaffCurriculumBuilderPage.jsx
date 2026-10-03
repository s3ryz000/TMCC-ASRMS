import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, Navigate, useBlocker, useNavigate, useParams } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { FiChevronLeft } from 'react-icons/fi';
import { staffApi } from '../../lib/api/staffApi';
import { parseApiError } from '../../lib/api/errors';
import { queryKeys } from '../../lib/react-query/queryKeys';
import { invalidateCurriculum } from '../../lib/react-query/curriculumInvalidation';
import { staffToast } from '../../lib/notifications';
import { useAuth } from '../../contexts/AuthContext';
import ConfirmDialog from '../../components/ui/ConfirmDialog';
import CurriculumGrid from '../../components/staff/curriculum/CurriculumGrid';
import AddSubjectPanel from '../../components/staff/curriculum/AddSubjectPanel';
import EntryActionsMenu from '../../components/staff/curriculum/EntryActionsMenu';
import {
  computeGridTotals,
  gridTotalsFromApi,
  joinSubjectCode,
  prerequisiteText,
  splitCurriculumErrors,
  termLabel,
} from '../../features/catalog/curriculumLayout';
import { EMPTY_PROGRAM, PROGRAM_FIELDS, toPayload, validateCatalogForm } from '../../features/catalog/catalogForms';

const PROGRAMS_PATH = '/staff/catalog/programs';
const curriculumPath = (programId) => `/staff/catalog/programs/${programId}/curriculum`;

const cardClass = 'bg-white rounded-xl shadow-[0_4px_14px_rgba(0,0,0,0.08)] border border-gray-100';
const primaryButton = 'py-2.5 px-5 rounded-lg text-sm font-medium bg-tmcc text-white hover:bg-tmcc-dark focus:outline-none focus:ring-2 focus:ring-tmcc/30 disabled:opacity-70';
const secondaryButton = 'py-2.5 px-5 rounded-lg text-sm font-medium bg-gray-200 text-gray-800 hover:bg-gray-300';

const Alert = ({ children }) => (
  <div className="mb-4 p-4 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm" role="alert">{children}</div>
);

/**
 * The whole subject catalogue (archived too, for duplicate-code checks and
 * "Used in N programs") and the CHED prefixes, shared by both modes.
 */
function useBuilderCatalogue() {
  const subjectsQuery = useQuery({
    queryKey: [...queryKeys.staff.subjects(), 'builder-all'],
    queryFn: () => staffApi.getSubjects({ include_archived: 1 }).then((d) => d?.subjects ?? []),
    staleTime: 60_000,
  });
  const prefixesQuery = useQuery({
    queryKey: queryKeys.staff.subjectPrefixes(),
    queryFn: () => staffApi.getSubjectPrefixes().then((d) => d?.prefixes ?? []),
    staleTime: 60_000,
  });

  const subjectsById = useMemo(() => new Map((subjectsQuery.data ?? []).map((s) => [s.id, s])), [subjectsQuery.data]);
  const catalogueCodes = useMemo(() => new Set((subjectsQuery.data ?? []).map((s) => joinSubjectCode(s.code, ''))), [subjectsQuery.data]);

  return {
    subjectsById,
    catalogueCodes,
    prefixes: prefixesQuery.data ?? [],
    isLoading: subjectsQuery.isLoading || prefixesQuery.isLoading,
    error: subjectsQuery.error || prefixesQuery.error,
  };
}

/** New Curriculum and Edit Curriculum (#70): registrar only; admins go to the read-only pages. */
const StaffCurriculumBuilderPage = ({ mode }) => {
  const { role } = useAuth();
  const { programId } = useParams();

  if (role !== 'staff') {
    return <Navigate to={mode === 'edit' ? curriculumPath(programId) : `${PROGRAMS_PATH}/view`} replace />;
  }

  return mode === 'edit' ? <EditCurriculum programId={programId} /> : <NewCurriculum />;
};

// ─────────────────────────────────────────────────────────────── New mode

/**
 * The curriculum is built in page state and saved once with
 * POST /staff/curriculums; nothing is written before Save.
 */
const NewCurriculum = () => {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const catalogue = useBuilderCatalogue();

  const [program, setProgram] = useState(EMPTY_PROGRAM);
  const [programErrors, setProgramErrors] = useState({});
  const [entries, setEntries] = useState([]);
  const [entryErrors, setEntryErrors] = useState({});
  const [generalErrors, setGeneralErrors] = useState([]);
  const [panelTerm, setPanelTerm] = useState(null);
  const [saving, setSaving] = useState(false);
  const nextKey = useRef(1);
  const saved = useRef(false);

  const dirty = entries.length > 0 || PROGRAM_FIELDS.some((f) => String(program[f.name] ?? '').trim() !== '');

  // Leaving with unsaved work asks first: in-app navigation and closing the tab.
  const blocker = useBlocker(
    ({ currentLocation, nextLocation }) => dirty && !saved.current && currentLocation.pathname !== nextLocation.pathname,
  );
  useEffect(() => {
    if (!dirty) return undefined;
    const warn = (e) => {
      e.preventDefault();
      e.returnValue = '';
    };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty]);

  const programCode = String(program.code ?? '').trim() || 'this program';

  const rows = useMemo(
    () =>
      entries.map((entry) => {
        const subject = entry.subject ?? entry.newSubject;
        const others = entry.subject?.programs ?? [];
        return {
          key: entry.key,
          yearLevel: entry.yearLevel,
          semester: entry.semester,
          code: subject.code,
          title: subject.title,
          units: Number(subject.units) || 0,
          archived: false,
          isNew: Boolean(entry.newSubject),
          prerequisites: '',
          unresolvedPrerequisites: '',
          // Shared once saved: the programs already using it plus this one.
          usedIn: others.length > 0 ? others.length + 1 : null,
          usedInCodes: [...others, programCode],
          errors: entryErrors[entry.key],
        };
      }),
    [entries, entryErrors, programCode],
  );

  const totals = useMemo(() => computeGridTotals(rows), [rows]);
  const placedSubjectIds = useMemo(() => new Set(entries.filter((e) => e.subject).map((e) => e.subject.id)), [entries]);
  const takenCodes = useMemo(
    () => new Set([...catalogue.catalogueCodes, ...entries.filter((e) => e.newSubject).map((e) => e.newSubject.code)]),
    [catalogue.catalogueCodes, entries],
  );

  const clearEntryError = (key) => setEntryErrors(({ [key]: _removed, ...rest }) => rest);

  const addEntry = (term, fields) => {
    const key = `draft-${nextKey.current++}`;
    setEntries((list) => [...list, { key, yearLevel: term.yearLevel, semester: term.semester, subject: null, newSubject: null, ...fields }]);
    setGeneralErrors([]);
  };

  const handleAddExisting = async (subject) => {
    addEntry(panelTerm, { subject: { id: subject.id, code: subject.code, title: subject.title, units: subject.units, programs: subject.programs ?? [] } });
    staffToast.success(`${subject.code} added`, termLabel(panelTerm.yearLevel, panelTerm.semester));
  };

  const handleAddNew = async (newSubject) => {
    addEntry(panelTerm, { newSubject });
    staffToast.success(`${newSubject.code} added as a new subject`, termLabel(panelTerm.yearLevel, panelTerm.semester));
  };

  const handleMove = async (row, term) => {
    setEntries((list) => list.map((e) => (e.key === row.key ? { ...e, yearLevel: term.yearLevel, semester: term.semester } : e)));
    clearEntryError(row.key);
  };

  const handleRemove = async (row) => {
    setEntries((list) => list.filter((e) => e.key !== row.key));
    clearEntryError(row.key);
  };

  const handleSave = async () => {
    const clientErrors = validateCatalogForm(PROGRAM_FIELDS, program);
    setProgramErrors(clientErrors);
    setGeneralErrors(entries.length === 0 ? ['Add at least one subject to the curriculum.'] : []);
    if (Object.keys(clientErrors).length > 0 || entries.length === 0) {
      staffToast.error('Nothing was saved', 'Fix the highlighted fields first.');
      return;
    }

    // Entry order is the index the server keys its errors by.
    const order = [...entries];
    const payload = {
      program: toPayload(PROGRAM_FIELDS, program),
      entries: order.map((e) => ({
        ...(e.subject ? { subject_id: e.subject.id } : { new_subject: e.newSubject }),
        year_level: e.yearLevel,
        semester: e.semester,
      })),
    };

    setSaving(true);
    try {
      const result = await staffApi.createCurriculum(payload);
      saved.current = true;
      staffToast.success('Curriculum created', result?.message);
      const newId = result?.program?.id;
      invalidateCurriculum(queryClient, newId);
      queryClient.invalidateQueries({ queryKey: queryKeys.staff.subjectPrefixes() });
      navigate(newId ? curriculumPath(newId) : `${PROGRAMS_PATH}/view`);
    } catch (err) {
      const parsed = parseApiError(err);
      if (parsed.errors) {
        const split = splitCurriculumErrors(parsed.errors);
        setProgramErrors(split.program);
        setEntryErrors(Object.fromEntries(Object.entries(split.entries).map(([index, messages]) => [order[index]?.key, messages])));
        setGeneralErrors(split.other);
        const count = Object.keys(split.entries).length;
        staffToast.error('Nothing was saved', count ? `${count} ${count === 1 ? 'subject needs' : 'subjects need'} attention.` : parsed.message);
      } else {
        staffToast.error('Could not save the curriculum', parsed.message);
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <>
      <Link to={PROGRAMS_PATH} className="inline-flex items-center gap-1 mb-2 text-sm text-gray-600 no-underline hover:text-tmcc">
        <FiChevronLeft aria-hidden /> Programs
      </Link>
      <section className="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 className="m-0 text-2xl font-bold text-gray-800">New Curriculum</h2>
          <p className="mt-2 m-0 text-gray-600">Create the program and place its subjects. Nothing is saved until you choose Save curriculum.</p>
        </div>
        <div className="flex gap-3">
          <button type="button" onClick={() => navigate(PROGRAMS_PATH)} className={secondaryButton}>Cancel</button>
          <button type="button" onClick={handleSave} disabled={saving} className={primaryButton}>
            {saving ? 'Saving...' : 'Save curriculum'}
          </button>
        </div>
      </section>

      <section className={`${cardClass} p-5 mb-6`} aria-labelledby="program-details">
        <h3 id="program-details" className="m-0 mb-4 text-base font-semibold text-gray-800">Program details</h3>
        <div className="grid grid-cols-1 md:grid-cols-[12rem_1fr] gap-4">
          {PROGRAM_FIELDS.map((field) => {
            const id = `new-program-${field.name}`;
            const error = programErrors[field.name];
            const props = {
              id,
              value: program[field.name] ?? '',
              onChange: (e) => setProgram((p) => ({ ...p, [field.name]: e.target.value })),
              placeholder: field.placeholder,
              maxLength: field.maxLength,
              'aria-invalid': !!error,
              className: `w-full py-2.5 px-4 rounded-lg border text-base focus:outline-none focus:ring-2 focus:ring-tmcc/20 focus:border-tmcc ${error ? 'border-red-500 bg-red-50' : 'border-gray-300'}`,
            };
            return (
              <div key={field.name} className={field.multiline ? 'md:col-span-2' : ''}>
                <label htmlFor={id} className="block mb-1 text-sm font-medium text-gray-700">{field.label}</label>
                {field.multiline ? <textarea {...props} rows={2} /> : <input {...props} />}
                {error && <p className="mt-1 mb-0 text-xs text-red-600" role="alert">{error}</p>}
              </div>
            );
          })}
        </div>
      </section>

      {generalErrors.length > 0 && <Alert>{generalErrors.join(' ')}</Alert>}
      {catalogue.error && <Alert>{parseApiError(catalogue.error).message || 'Failed to load the subject catalogue.'}</Alert>}

      <CurriculumGrid
        rows={rows}
        totals={totals}
        canEdit
        onAdd={setPanelTerm}
        renderRowActions={(row, term) => (
          <EntryActionsMenu
            row={row}
            yearLevel={term.yearLevel}
            semester={term.semester}
            onMove={(target) => handleMove(row, target)}
            onRemove={() => handleRemove(row)}
          />
        )}
      />

      <div className="flex justify-end gap-3 mt-6">
        <button type="button" onClick={handleSave} disabled={saving} className={primaryButton}>
          {saving ? 'Saving...' : 'Save curriculum'}
        </button>
      </div>

      {panelTerm && (
        <AddSubjectPanel
          term={panelTerm}
          onClose={() => setPanelTerm(null)}
          placedSubjectIds={placedSubjectIds}
          takenCodes={takenCodes}
          prefixes={catalogue.prefixes}
          onAddExisting={handleAddExisting}
          onAddNew={handleAddNew}
        />
      )}

      <ConfirmDialog
        isOpen={blocker.state === 'blocked'}
        onClose={() => blocker.reset?.()}
        onConfirm={() => blocker.proceed?.()}
        title="Leave without saving?"
        message="This curriculum hasn't been saved. If you leave now, the program and its subjects are lost."
        confirmLabel="Leave"
        cancelLabel="Stay"
        variant="danger"
      />
    </>
  );
};

// ────────────────────────────────────────────────────────────── Edit mode

/**
 * Every change is written immediately through the entries API (#24); moves and
 * removals are checked against student records first (#28).
 */
const EditCurriculum = ({ programId }) => {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const catalogue = useBuilderCatalogue();
  const [panelTerm, setPanelTerm] = useState(null);
  const [pendingRemove, setPendingRemove] = useState(null);
  const [removing, setRemoving] = useState(false);

  // Same key, request and shape as the #71 page (raw response, archived included).
  const programsQuery = useQuery({
    queryKey: [...queryKeys.staff.programs(), { includeArchived: true }],
    queryFn: () => staffApi.getPrograms({ include_archived: 1 }),
    staleTime: 60_000,
  });
  // Under the #71 page's key, so invalidateCurriculum refreshes both.
  const curriculumQuery = useQuery({
    queryKey: [...queryKeys.staff.programCurriculum(programId), 'builder'],
    queryFn: () => staffApi.getProgramCurriculum(programId),
  });

  const program = useMemo(
    () => (programsQuery.data?.programs ?? []).find((p) => String(p.id) === String(programId)),
    [programsQuery.data, programId],
  );

  const rows = useMemo(
    () =>
      (curriculumQuery.data?.curriculum ?? []).map((row) => {
        const { linked, unresolved } = prerequisiteText(row);
        const programs = catalogue.subjectsById.get(row.subject_id)?.programs ?? [];
        return {
          key: String(row.id),
          entryId: row.id,
          yearLevel: Number(row.year_level),
          semester: Number(row.semester),
          code: row.subject?.code ?? '',
          title: row.subject?.title ?? '',
          units: Number(row.subject?.units) || 0,
          archived: Boolean(row.subject?.archived),
          isNew: false,
          prerequisites: linked,
          unresolvedPrerequisites: unresolved,
          usedIn: programs.length,
          usedInCodes: programs,
        };
      }),
    [curriculumQuery.data, catalogue.subjectsById],
  );

  const totals = useMemo(() => gridTotalsFromApi(curriculumQuery.data?.totals), [curriculumQuery.data]);
  const placedSubjectIds = useMemo(
    () => new Set((curriculumQuery.data?.curriculum ?? []).map((row) => row.subject_id)),
    [curriculumQuery.data],
  );

  const refresh = useCallback(() => invalidateCurriculum(queryClient, programId), [queryClient, programId]);

  const place = async (subjectId) => {
    const result = await staffApi.addCurriculumEntry(programId, {
      subject_id: subjectId,
      year_level: panelTerm.yearLevel,
      semester: panelTerm.semester,
    });
    staffToast.success('Subject added', result?.message);
    refresh();
  };

  const handleAddExisting = (subject) => place(subject.id);

  const handleAddNew = async (newSubject) => {
    // A 422 here (duplicate code, similar title) goes back to the panel's fields.
    const created = await staffApi.createSubject(newSubject);
    queryClient.invalidateQueries({ queryKey: queryKeys.staff.subjectPrefixes() });
    try {
      await place(created.subject.id);
    } catch (err) {
      refresh();
      staffToast.error(`${created.subject.code} was created but not placed`, parseApiError(err).message);
    }
  };

  const handleMove = async (row, term) => {
    try {
      const result = await staffApi.moveCurriculumEntry(row.entryId, { year_level: term.yearLevel, semester: term.semester });
      staffToast.success('Subject moved', result?.message);
      refresh();
    } catch (err) {
      staffToast.error(`Could not move ${row.code}`, parseApiError(err).message);
      throw err;
    }
  };

  const confirmRemove = async () => {
    const row = pendingRemove;
    setRemoving(true);
    try {
      const result = await staffApi.removeCurriculumEntry(row.entryId);
      staffToast.success('Subject removed', result?.message);
      refresh();
    } catch (err) {
      staffToast.error(`Could not remove ${row.code}`, parseApiError(err).message);
    } finally {
      setRemoving(false);
      setPendingRemove(null);
    }
  };

  if (programsQuery.isLoading || curriculumQuery.isLoading || catalogue.isLoading) {
    return <p className="py-8 text-center text-gray-500">Loading curriculum...</p>;
  }

  const loadError = programsQuery.error || curriculumQuery.error;
  if (loadError || !program) {
    return (
      <>
        <Link to={`${PROGRAMS_PATH}/view`} className="inline-flex items-center gap-1 mb-2 text-sm text-gray-600 no-underline hover:text-tmcc">
          <FiChevronLeft aria-hidden /> Back to programs
        </Link>
        {loadError ? <Alert>{parseApiError(loadError).message || 'Failed to load the curriculum.'}</Alert> : <p className="m-0 text-gray-700">Program not found.</p>}
      </>
    );
  }

  const archived = Boolean(program.archived);

  return (
    <>
      <Link to={curriculumPath(programId)} className="inline-flex items-center gap-1 mb-2 text-sm text-gray-600 no-underline hover:text-tmcc">
        <FiChevronLeft aria-hidden /> Back to curriculum
      </Link>
      <section className="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 className="m-0 text-2xl font-bold text-gray-800">
            Edit curriculum: {program.code} — {program.name}
          </h2>
          {program.description && <p className="mt-2 m-0 text-gray-600">{program.description}</p>}
          <p className="mt-2 m-0 text-sm text-gray-600">
            Changes are saved as you make them. Subjects that students already have records for can't be moved or removed.
          </p>
        </div>
        <button type="button" onClick={() => navigate(curriculumPath(programId))} className={primaryButton}>
          Done
        </button>
      </section>

      {archived && (
        <div className="mb-4 p-4 rounded-lg bg-gray-100 border border-gray-200 text-gray-700 text-sm" role="note">
          This program is archived: no new subjects can be placed in it.
        </div>
      )}
      {catalogue.error && <Alert>{parseApiError(catalogue.error).message || 'Failed to load the subject catalogue.'}</Alert>}

      <CurriculumGrid
        rows={rows}
        totals={totals}
        canEdit
        canAdd={!archived}
        onAdd={setPanelTerm}
        renderRowActions={(row, term) => (
          <EntryActionsMenu
            row={row}
            yearLevel={term.yearLevel}
            semester={term.semester}
            loadImpact={() => staffApi.getCurriculumImpact(row.entryId)}
            onMove={(target) => handleMove(row, target)}
            onRemove={async () => setPendingRemove(row)}
          />
        )}
      />

      {panelTerm && (
        <AddSubjectPanel
          term={panelTerm}
          onClose={() => setPanelTerm(null)}
          placedSubjectIds={placedSubjectIds}
          takenCodes={catalogue.catalogueCodes}
          prefixes={catalogue.prefixes}
          onAddExisting={handleAddExisting}
          onAddNew={handleAddNew}
        />
      )}

      <ConfirmDialog
        isOpen={pendingRemove !== null}
        onClose={() => !removing && setPendingRemove(null)}
        onConfirm={confirmRemove}
        title={`Remove ${pendingRemove?.code ?? ''}?`}
        message={`${pendingRemove?.code ?? ''} will be removed from ${program.code}. The subject stays in the catalogue.`}
        confirmLabel="Remove"
        variant="danger"
        loading={removing}
      />
    </>
  );
};

export default StaffCurriculumBuilderPage;
