import React, { useEffect, useRef, useState } from 'react';
import Modal from '../../ui/Modal';
import { parseApiError } from '../../../lib/api/errors';
import { termLabel } from '../../../features/catalog/curriculumLayout';

/**
 * Prerequisite picker for one curriculum row (#27): subjects of earlier terms
 * of this curriculum, AND/OR, and how many students already have records for
 * the subject (the change applies to future enrollments only).
 *
 * candidates: [{ value, code, title, yearLevel, semester }] (prerequisiteCandidates)
 * selected: values currently required; logic: 'AND' | 'OR'
 * loadImpact: () => Promise<impact report>, edit mode only
 * requiresAllOtherSubjects: the program-completion marker (#82), edit mode
 *   only; when it is undefined the checkbox is not shown.
 * onSave({ values, logic, requiresAllOtherSubjects }) returns a promise; its
 *   errors are shown here.
 */
const PrerequisitesDialog = ({ row, candidates, selected, logic, requiresAllOtherSubjects, loadImpact, onSave, onClose }) => {
  const candidateValues = new Set(candidates.map((c) => c.value));
  const [chosen, setChosen] = useState(() => selected.filter((v) => candidateValues.has(v)));
  const [mode, setMode] = useState(logic === 'OR' ? 'OR' : 'AND');
  const showAfterAll = requiresAllOtherSubjects !== undefined;
  const [afterAll, setAfterAll] = useState(Boolean(requiresAllOtherSubjects));
  const [impact, setImpact] = useState(loadImpact ? { status: 'loading' } : { status: 'none' });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);
  const dropped = selected.filter((v) => !candidateValues.has(v)).length;

  // Fetched once when the dialog opens.
  const loadImpactRef = useRef(loadImpact);
  useEffect(() => {
    if (!loadImpactRef.current) return undefined;
    let active = true;
    loadImpactRef.current()
      .then((report) => active && setImpact({ status: 'ready', count: Number(report?.students?.count) || 0 }))
      .catch(() => active && setImpact({ status: 'error' }));
    return () => {
      active = false;
    };
  }, []);

  const toggle = (value) => setChosen((list) => (list.includes(value) ? list.filter((v) => v !== value) : [...list, value]));

  const save = async () => {
    setSaving(true);
    setError(null);
    try {
      await onSave({ values: chosen, logic: mode, ...(showAfterAll && { requiresAllOtherSubjects: afterAll }) });
      onClose();
    } catch (err) {
      const parsed = parseApiError(err);
      setError(parsed.errors ? Object.values(parsed.errors).flat().join(' ') : parsed.message);
    } finally {
      setSaving(false);
    }
  };

  let impactText = 'Applies to future enrollments.';
  if (impact.status === 'loading') impactText = 'Applies to future enrollments. Checking student records...';
  if (impact.status === 'ready') {
    impactText = impact.count === 0
      ? 'Applies to future enrollments; no student has taken this subject yet.'
      : `Applies to future enrollments; ${impact.count} ${impact.count === 1 ? 'student has' : 'students have'} already taken this subject. Their records don't change.`;
  }

  return (
    <Modal isOpen onClose={() => !saving && onClose()} title={`Prerequisites for ${row.code}`} titleId="prerequisites-title" maxWidth="max-w-lg" closeOnBackdrop={!saving}>
      <div className="px-6 py-5">
        <p className="m-0 mb-3 p-3 rounded-lg bg-blue-50 border border-blue-200 text-sm text-blue-900" role="note">{impactText}</p>
        {dropped > 0 && (
          <p className="m-0 mb-3 text-xs text-amber-800">
            {dropped} current {dropped === 1 ? 'prerequisite is' : 'prerequisites are'} no longer in an earlier term and will be removed when you save.
          </p>
        )}

        {candidates.length === 0 ? (
          <p className="m-0 text-sm text-gray-600">
            No subjects in earlier terms yet. Place the prerequisite in an earlier term first.
          </p>
        ) : (
          <fieldset className="m-0 p-0 border-0">
            <legend className="mb-2 text-sm font-medium text-gray-700">Subjects in earlier terms</legend>
            <ul className="m-0 p-0 list-none max-h-64 overflow-y-auto space-y-1">
              {candidates.map((c) => (
                <li key={c.value}>
                  <label className="flex items-start gap-2 p-2 rounded-lg hover:bg-gray-50 cursor-pointer text-sm">
                    <input type="checkbox" checked={chosen.includes(c.value)} onChange={() => toggle(c.value)} className="mt-0.5" />
                    <span>
                      <span className="font-medium text-gray-800">{c.code}</span> <span className="text-gray-700">{c.title}</span>
                      <span className="block text-xs text-gray-500">{termLabel(c.yearLevel, c.semester)}</span>
                    </span>
                  </label>
                </li>
              ))}
            </ul>
          </fieldset>
        )}

        <fieldset className="mt-4 m-0 p-0 border-0">
          <legend className="mb-1 text-sm font-medium text-gray-700">When there is more than one</legend>
          <div className="flex flex-wrap gap-4 text-sm text-gray-800">
            <label className="inline-flex items-center gap-2">
              <input type="radio" name="prerequisite-logic" checked={mode === 'AND'} onChange={() => setMode('AND')} /> All of them (AND)
            </label>
            <label className="inline-flex items-center gap-2">
              <input type="radio" name="prerequisite-logic" checked={mode === 'OR'} onChange={() => setMode('OR')} /> Any one of them (OR)
            </label>
          </div>
        </fieldset>

        {showAfterAll && (
          <label className="mt-4 flex items-start gap-2 p-3 rounded-lg border border-gray-200 text-sm cursor-pointer">
            <input type="checkbox" checked={afterAll} onChange={(e) => setAfterAll(e.target.checked)} className="mt-0.5" />
            <span>
              <span className="font-medium text-gray-800">After all other subjects</span>
              <span className="block text-xs text-gray-500">
                {row.code} can be enrolled only when every other subject of this program is Passed or Credited (e.g. PRACTICUM).
              </span>
            </span>
          </label>
        )}

        {error && <p className="m-0 mt-3 p-3 rounded-lg bg-red-50 border border-red-200 text-sm text-red-800" role="alert">{error}</p>}

        <div className="flex justify-end gap-3 mt-6">
          <button type="button" onClick={onClose} disabled={saving} className="py-2.5 px-5 rounded-lg text-sm font-medium bg-gray-200 text-gray-800 hover:bg-gray-300 disabled:opacity-70">
            Cancel
          </button>
          <button type="button" onClick={save} disabled={saving} className="py-2.5 px-5 rounded-lg text-sm font-medium bg-tmcc text-white hover:bg-tmcc-dark disabled:opacity-70">
            {saving ? 'Saving...' : 'Save prerequisites'}
          </button>
        </div>
      </div>
    </Modal>
  );
};

export default PrerequisitesDialog;
