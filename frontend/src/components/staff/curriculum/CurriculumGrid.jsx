import React from 'react';
import TermCard from './TermCard';
import { compareCodes, formatUnits, yearLabel } from '../../../features/catalog/curriculumLayout';

/**
 * The Year 1-4 × 1st/2nd semester grid of the curriculum builder (#70), with
 * year and program totals.
 *
 * rows: builder rows with yearLevel and semester (see TermCard)
 * totals: computeGridTotals() / gridTotalsFromApi() shape
 */
const CurriculumGrid = ({ rows, totals, canEdit, canAdd = canEdit, onAdd, renderRowActions }) => (
  <>
    {totals.years.map((year) => (
      <section key={year.yearLevel} className="mb-6" aria-labelledby={`builder-year-${year.yearLevel}`}>
        <div className="flex flex-wrap items-baseline justify-between gap-2 mb-2">
          <h3 id={`builder-year-${year.yearLevel}`} className="m-0 text-lg font-bold tracking-wide text-gray-800">
            {yearLabel(year.yearLevel)}
          </h3>
          <span className="text-sm text-gray-600">
            {formatUnits(year.units)} units · {year.subjects} {year.subjects === 1 ? 'subject' : 'subjects'}
          </span>
        </div>
        <div className="grid grid-cols-1 xl:grid-cols-2 gap-4 items-start">
          {totals.terms
            .filter((t) => t.yearLevel === year.yearLevel)
            .map((term) => (
              <TermCard
                key={term.semester}
                yearLevel={term.yearLevel}
                semester={term.semester}
                rows={rows
                  .filter((r) => r.yearLevel === term.yearLevel && r.semester === term.semester)
                  .sort((a, b) => compareCodes(a.code, b.code))}
                total={term}
                canEdit={canEdit}
                canAdd={canAdd}
                onAdd={onAdd}
                renderRowActions={(row) => renderRowActions(row, term)}
              />
            ))}
        </div>
      </section>
    ))}

    <p className="m-0 text-base font-semibold text-gray-800">
      Program total: {formatUnits(totals.program.units)} units
      <span className="ml-2 font-normal text-gray-600">
        ({totals.program.subjects} {totals.program.subjects === 1 ? 'subject' : 'subjects'})
      </span>
    </p>
  </>
);

export default CurriculumGrid;
