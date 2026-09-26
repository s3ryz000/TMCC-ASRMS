/**
 * Phrase a subject's prerequisites the way the backend phrases a blocked
 * subject: "PROG1", "PROG1 and PROG2", "PROG1 or PROG2".
 *
 * @param {string[]} codes
 * @param {'AND'|'OR'} [logic]
 * @returns {string} Empty when there are no prerequisites.
 */
export function formatPrerequisites(codes, logic = 'AND') {
  const list = (codes || []).filter(Boolean);
  if (list.length === 0) return '';
  if (list.length === 1) return list[0];
  if (logic === 'OR') return list.join(' or ');
  return `${list.slice(0, -1).join(', ')} and ${list[list.length - 1]}`;
}

/**
 * The picker's note for one available subject: why it is blocked, which
 * prerequisites it has already met, or that it has none.
 *
 * @returns {{ tone: 'blocked'|'met'|'none', text: string }}
 */
export function prerequisiteNote(subject) {
  if (!subject.eligible) {
    return { tone: 'blocked', text: subject.blocked_reason || 'Not eligible this term.' };
  }

  const codes = subject.prerequisite_codes || [];
  if (codes.length === 0) {
    return { tone: 'none', text: 'No prerequisite' };
  }

  const label = codes.length > 1 && subject.prerequisite_logic !== 'OR' ? 'Prerequisites met' : 'Prerequisite met';
  return { tone: 'met', text: `${label}: ${formatPrerequisites(codes, subject.prerequisite_logic)}` };
}
