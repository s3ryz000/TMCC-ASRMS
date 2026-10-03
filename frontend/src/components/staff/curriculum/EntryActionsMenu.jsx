import React, { useEffect, useRef, useState } from 'react';
import { FiMoreVertical } from 'react-icons/fi';
import { parseApiError } from '../../../lib/api/errors';
import { CURRICULUM_TERMS, impactRefusal, termLabel } from '../../../features/catalog/curriculumLayout';

/**
 * Move / remove menu for one curriculum row (#70).
 *
 * In edit mode `loadImpact` fetches GET /staff/curriculum/{id}/impact when the
 * menu opens; an action #28 refuses is not offered and its reason is shown.
 * onMove(term) and onRemove() return promises; the page reports their errors.
 */
const EntryActionsMenu = ({ row, yearLevel, semester, loadImpact, onMove, onRemove }) => {
  const [open, setOpen] = useState(false);
  const [impact, setImpact] = useState({ status: 'idle', report: null, error: null });
  const [moving, setMoving] = useState(false);
  const [target, setTarget] = useState('');
  const [busy, setBusy] = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    if (!open) return undefined;
    const close = (e) => {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    };
    const escape = (e) => e.key === 'Escape' && setOpen(false);
    document.addEventListener('mousedown', close);
    document.addEventListener('keydown', escape);
    return () => {
      document.removeEventListener('mousedown', close);
      document.removeEventListener('keydown', escape);
    };
  }, [open]);

  const toggle = async () => {
    const next = !open;
    setOpen(next);
    setMoving(false);
    setTarget('');
    if (!next || !loadImpact) return;

    setImpact({ status: 'loading', report: null, error: null });
    try {
      setImpact({ status: 'ready', report: await loadImpact(), error: null });
    } catch (err) {
      setImpact({ status: 'error', report: null, error: parseApiError(err).message });
    }
  };

  const run = async (action) => {
    setBusy(true);
    try {
      await action();
      setOpen(false);
    } catch {
      // The page shows the error (toast); keep the menu open to retry.
    } finally {
      setBusy(false);
    }
  };

  const checking = loadImpact && impact.status !== 'ready';
  const moveRefusal = loadImpact ? impactRefusal(impact.report, 'move') : null;
  const removeRefusal = loadImpact ? impactRefusal(impact.report, 'remove') : null;
  const refusal = moveRefusal || removeRefusal;
  const otherTerms = CURRICULUM_TERMS.filter((t) => !(t.yearLevel === yearLevel && t.semester === semester));
  const itemClass = 'block w-full text-left py-2 px-3 text-sm rounded-md hover:bg-gray-100 disabled:opacity-50 disabled:hover:bg-transparent';

  return (
    <div className="relative inline-block text-left" ref={ref}>
      <button
        type="button"
        onClick={toggle}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={`Actions for ${row.code}`}
        className="p-1.5 rounded-lg text-gray-600 hover:bg-gray-100 hover:text-tmcc focus:outline-none focus:ring-2 focus:ring-tmcc/30"
      >
        <FiMoreVertical aria-hidden />
      </button>

      {open && (
        <div role="menu" className="absolute right-0 z-30 mt-1 w-72 p-2 bg-white rounded-lg shadow-lg border border-gray-200 text-gray-800">
          {checking && impact.status === 'loading' && <p className="m-0 p-2 text-sm text-gray-500">Checking student records...</p>}
          {impact.status === 'error' && (
            <p className="m-0 p-2 text-sm text-red-700" role="alert">{impact.error || 'Could not check student records.'}</p>
          )}
          {refusal && (
            <p className="m-0 mb-1 p-2 rounded-md bg-amber-50 border border-amber-200 text-xs text-amber-900" role="note">
              {refusal}
            </p>
          )}

          {!checking && !moving && (
            <>
              {!moveRefusal && (
                <button type="button" role="menuitem" className={itemClass} onClick={() => setMoving(true)} disabled={busy}>
                  Move to another term
                </button>
              )}
              {!removeRefusal && (
                <button type="button" role="menuitem" className={`${itemClass} text-red-700`} onClick={() => run(onRemove)} disabled={busy}>
                  {busy ? 'Removing...' : 'Remove from curriculum'}
                </button>
              )}
            </>
          )}

          {!checking && moving && (
            <div className="p-1">
              <label htmlFor={`move-${row.key}`} className="block mb-1 text-xs font-medium text-gray-700">
                Move {row.code} to
              </label>
              <select
                id={`move-${row.key}`}
                value={target}
                onChange={(e) => setTarget(e.target.value)}
                className="w-full py-2 px-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-tmcc/20 focus:border-tmcc"
              >
                <option value="">Choose a term</option>
                {otherTerms.map((t) => (
                  <option key={`${t.yearLevel}-${t.semester}`} value={`${t.yearLevel}-${t.semester}`}>
                    {termLabel(t.yearLevel, t.semester)}
                  </option>
                ))}
              </select>
              <div className="flex justify-end gap-2 mt-2">
                <button type="button" onClick={() => setMoving(false)} className="py-1.5 px-3 rounded-lg text-xs font-medium bg-gray-200 text-gray-800 hover:bg-gray-300">
                  Back
                </button>
                <button
                  type="button"
                  disabled={!target || busy}
                  onClick={() => {
                    const [y, s] = target.split('-').map(Number);
                    run(() => onMove({ yearLevel: y, semester: s }));
                  }}
                  className="py-1.5 px-3 rounded-lg text-xs font-medium bg-tmcc text-white hover:bg-tmcc-dark disabled:opacity-60"
                >
                  {busy ? 'Moving...' : 'Move'}
                </button>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
};

export default EntryActionsMenu;
