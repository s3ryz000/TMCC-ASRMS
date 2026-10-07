/**
 * What the Request Documents page offers for a document, given the student's
 * latest request for it (#90):
 *   none / rejected → 'request' (ask again)
 *   pending         → 'processing'
 *   approved        → 'slip' (download the approval slip; the appointment shows)
 *   released        → 'download' (the document itself)
 *
 * @param {string|null} status
 * @returns {'request'|'processing'|'slip'|'download'}
 */
export function requestAction(status) {
  if (!status || status === 'rejected') return 'request';
  if (status === 'approved') return 'slip';
  if (status === 'released') return 'download';
  return 'processing';
}
