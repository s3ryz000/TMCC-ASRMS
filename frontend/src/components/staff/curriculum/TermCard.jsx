import React from 'react';
import { FiPlus } from 'react-icons/fi';
import { MAXIMUM_UNITS, formatUnits, semesterLabel } from '../../../features/catalog/curriculumLayout';

const thClass = 'py-2 px-3 text-left border-b-2 border-gray-200 bg-gray-100 font-semibold text-gray-700';
const tdClass = 'py-2 px-3 border-b border-gray-100 align-top';

/**
 * One semester of the curriculum builder grid (#70): its subjects, a running
 * total and, for the registrar, an "Add subject" button and a menu per row.
 *
 * rows: [{ key, code, title, units, archived, isNew, prerequisites, unresolvedPrerequisites,
 *          usedIn, usedInCodes, errors }]
 * total: { units, subjects, overMax }
 */
const TermCard = ({ yearLevel, semester, rows, total, canEdit, canAdd = canEdit, onAdd, renderRowActions, renderPrerequisiteAction }) => {
  const headingId = `term-${yearLevel}-${semester}`;

  return (
    <div
      className={`bg-white rounded-xl shadow-[0_4px_14px_rgba(0,0,0,0.08)] border overflow-visible ${total.overMax ? 'border-red-300' : 'border-gray-100'}`}
      aria-labelledby={headingId}
      role="region"
    >
      <div className="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 bg-gray-50 border-b border-gray-200 rounded-t-xl">
        <h4 id={headingId} className="m-0 text-sm font-semibold tracking-wide text-gray-700">
          {semesterLabel(semester)}
        </h4>
        {canAdd && (
          <button
            type="button"
            onClick={() => onAdd({ yearLevel, semester })}
            className="inline-flex items-center gap-1 py-1 px-2.5 rounded-lg text-xs font-medium bg-tmcc text-white hover:bg-tmcc-dark focus:outline-none focus:ring-2 focus:ring-tmcc/30"
          >
            <FiPlus aria-hidden /> Add subject
          </button>
        )}
      </div>

      {rows.length === 0 ? (
        <p className="m-0 px-4 py-4 text-sm text-gray-500">No subjects in this term yet.</p>
      ) : (
        <table className="w-full text-sm border-collapse">
          <thead>
            <tr>
              <th className={`${thClass} w-24`}>Subject</th>
              <th className={thClass}>Description</th>
              <th className={`${thClass} w-14 text-center`}>Units</th>
              <th className={`${thClass} w-32`}>Pre-requisites</th>
              {canEdit && <th className={`${thClass} w-12`}><span className="sr-only">Actions</span></th>}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.key} className={row.errors?.length ? 'bg-red-50' : 'text-gray-800'}>
                <td className={`${tdClass} font-medium whitespace-nowrap`}>
                  {row.code}
                  {row.isNew && (
                    <span className="ml-1.5 inline-block py-0.5 px-1.5 rounded-full text-[0.6rem] font-medium bg-emerald-100 text-emerald-800 align-middle">
                      New
                    </span>
                  )}
                  {row.archived && (
                    <span className="ml-1.5 inline-block py-0.5 px-1.5 rounded-full text-[0.6rem] font-medium bg-gray-200 text-gray-600 align-middle">
                      Archived
                    </span>
                  )}
                </td>
                <td className={tdClass}>
                  {row.title}
                  {row.usedIn > 1 && (
                    <span className="block text-xs text-gray-500" title={row.usedInCodes?.join(', ')}>
                      Used in {row.usedIn} programs
                    </span>
                  )}
                  {row.errors?.map((message) => (
                    <span key={message} className="block mt-0.5 text-xs text-red-700" role="alert">
                      {message}
                    </span>
                  ))}
                </td>
                <td className={`${tdClass} text-center`}>{formatUnits(row.units)}</td>
                <td className={`${tdClass} text-gray-700`}>
                  {row.prerequisites || (row.unresolvedPrerequisites ? '' : <span className="text-gray-400">—</span>)}
                  {/* A prerequisite the seeder couldn't link (#20): enrollment is blocked until the registrar sets it (#27). */}
                  {row.unresolvedPrerequisites && (
                    <span
                      className="block mt-0.5 py-0.5 px-1.5 rounded-md text-[0.7rem] font-medium bg-amber-100 text-amber-900"
                      title="Not linked to a subject; enrollment in this subject is blocked until the prerequisites are set"
                    >
                      Unresolved prerequisite: {row.unresolvedPrerequisites}
                    </span>
                  )}
                  {canEdit && renderPrerequisiteAction && <span className="block mt-1">{renderPrerequisiteAction(row)}</span>}
                </td>
                {canEdit && <td className={`${tdClass} text-right`}>{renderRowActions(row)}</td>}
              </tr>
            ))}
          </tbody>
        </table>
      )}

      <div className={`flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-sm font-semibold border-t rounded-b-xl ${total.overMax ? 'bg-red-50 border-red-200 text-red-800' : 'bg-gray-50 border-gray-200 text-gray-800'}`}>
        <span>
          Total: {formatUnits(total.units)} units
          <span className="ml-1 font-normal text-gray-600">({total.subjects} {total.subjects === 1 ? 'subject' : 'subjects'})</span>
        </span>
        {total.overMax && <span role="status">Over the {MAXIMUM_UNITS}-unit maximum</span>}
      </div>
    </div>
  );
};

export default TermCard;
