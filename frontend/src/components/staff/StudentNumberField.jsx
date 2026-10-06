import React, { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { staffApi } from '../../lib/api/staffApi';
import { queryKeys } from '../../lib/react-query/queryKeys';
import { useAuth } from '../../contexts/AuthContext';
import { cleanPart, composeStudentNumber, partOf } from '../../features/students/studentNumber';

/** Waits until typing pauses before checking. */
function useDebounced(value, delay = 350) {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const id = setTimeout(() => setDebounced(value), delay);
    return () => clearTimeout(id);
  }, [value, delay]);
  return debounced;
}

/**
 * The student number as the registrar enters it (#56): a fixed, read-only
 * 2-digit year prefix from the enrollment date and a 4-digit input, checked
 * live against the server. A taken number offers "Use next available".
 *
 * part / onPartChange: the 4 digits; prefix: "26" ('' until there is a date)
 * ignore: a number that counts as free (the student's own, when changing)
 */
const StudentNumberField = ({ id, label = 'Student Number *', prefix, part, onPartChange, error, ignore, hint }) => {
  const { role } = useAuth();
  const number = composeStudentNumber(prefix, part);
  const checked = useDebounced(number);

  // The check is registrar-only on the server (#56); admins don't call it.
  const check = useQuery({
    queryKey: queryKeys.staff.studentNumberCheck(checked),
    queryFn: () => staffApi.checkStudentNumber(checked),
    enabled: role === 'staff' && Boolean(checked) && checked === number && checked !== ignore,
    staleTime: 5_000,
    retry: false,
  });

  const result = check.data && check.data.number === number ? check.data : null;
  const holder = result?.conflict;
  const inputClass = `w-24 py-2.5 px-3 rounded-r-lg text-base border tracking-widest focus:outline-none focus:ring-2 focus:ring-tmcc/20 focus:border-tmcc ${
    error ? 'border-red-500 bg-red-50' : 'border-gray-300'
  }`;

  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="text-sm font-medium text-gray-600">{label}</label>
      <div className="flex items-stretch">
        <span
          className="inline-flex items-center px-3 rounded-l-lg border border-r-0 border-gray-300 bg-gray-100 text-base font-semibold tracking-widest text-gray-700 select-none"
          title="The enrollment year; set by the Enrollment Date"
          aria-label={prefix ? `Year prefix ${prefix}` : 'Year prefix: choose the enrollment date first'}
        >
          {prefix || '––'}
        </span>
        <input
          id={id}
          inputMode="numeric"
          autoComplete="off"
          value={part}
          onChange={(e) => onPartChange(cleanPart(e.target.value))}
          placeholder="0001"
          maxLength={4}
          disabled={!prefix}
          className={inputClass}
          aria-invalid={!!error}
          aria-describedby={`${id}-status`}
        />
      </div>

      <div id={`${id}-status`} className="text-xs" aria-live="polite">
        {!prefix && <span className="text-gray-500">Choose the enrollment date first.</span>}
        {prefix && !number && <span className="text-gray-500">{hint ?? 'Type the last 4 digits.'}</span>}
        {number && number === ignore && <span className="text-gray-500">This is the current number.</span>}
        {number && number !== ignore && role !== 'staff' && <span className="text-gray-500">Availability is checked by the registrar.</span>}
        {number && number !== ignore && check.isFetching && !result && <span className="text-gray-500">Checking {number}...</span>}
        {result?.available && <span className="font-medium text-green-700">{number} is available.</span>}
        {result && !result.available && (
          <span className="flex flex-wrap items-center gap-2 text-red-700">
            <span>
              {number} is taken{holder?.name ? ` by ${holder.name}${holder.program ? ` (${holder.program})` : ''}` : ''}.
            </span>
            {result.next_available && (
              <button
                type="button"
                onClick={() => onPartChange(partOf(result.next_available))}
                className="py-1 px-2 rounded-md bg-tmcc text-white font-medium hover:bg-tmcc-dark focus:outline-none focus:ring-2 focus:ring-tmcc/30"
              >
                Use next available: {result.next_available}
              </button>
            )}
          </span>
        )}
        {check.isError && <span className="text-amber-700">Couldn't check availability; the number is checked again when you save.</span>}
      </div>
      {error && <span className="text-xs text-red-600" role="alert">{error}</span>}
    </div>
  );
};

export default StudentNumberField;
