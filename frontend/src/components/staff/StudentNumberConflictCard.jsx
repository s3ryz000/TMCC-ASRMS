import React from 'react';

const ROWS = [
  ['name', 'Name'],
  ['program', 'Program'],
  ['enrollment_date', 'Enrollment date'],
  ['date_of_birth', 'Birth date'],
];

/**
 * A refused save (#56): the student who already holds the number next to the
 * one being entered, so the registrar can compare with the paper records.
 * conflict: the 422's `conflict`; entered: the same fields for this student.
 */
const StudentNumberConflictCard = ({ conflict, entered, nextAvailable, onUseNext, onDismiss }) => (
  <div className="mb-5 p-4 rounded-xl border border-red-200 bg-red-50" role="alert">
    <p className="m-0 mb-3 text-sm font-semibold text-red-800">
      Student number {conflict.student_number} is already used. Compare with the paper records before choosing another.
    </p>
    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
      {[
        ['Already holds this number', conflict],
        ['Student being entered', entered],
      ].map(([title, person]) => (
        <div key={title} className="p-3 rounded-lg bg-white border border-gray-200">
          <p className="m-0 mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{title}</p>
          <dl className="m-0 grid grid-cols-[7rem_1fr] gap-y-1 text-sm">
            {ROWS.map(([key, label]) => (
              <React.Fragment key={key}>
                <dt className="text-gray-500">{label}</dt>
                <dd className="m-0 text-gray-800">{person?.[key] || '—'}</dd>
              </React.Fragment>
            ))}
          </dl>
        </div>
      ))}
    </div>
    <div className="flex flex-wrap gap-2 mt-3">
      {nextAvailable && (
        <button type="button" onClick={onUseNext} className="py-1.5 px-3 rounded-lg text-sm font-medium bg-tmcc text-white hover:bg-tmcc-dark">
          Use next available: {nextAvailable}
        </button>
      )}
      <button type="button" onClick={onDismiss} className="py-1.5 px-3 rounded-lg text-sm font-medium bg-gray-200 text-gray-800 hover:bg-gray-300">
        Dismiss
      </button>
    </div>
  </div>
);

export default StudentNumberConflictCard;
