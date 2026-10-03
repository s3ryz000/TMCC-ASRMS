import { queryKeys } from './queryKeys';

/**
 * Refetch what a curriculum edit (place, move, remove) changes: the program's
 * curriculum and totals, impact reports (prerequisite links), the subject list
 * (curriculum counts and programs) and the program list (curriculum counts).
 */
export function invalidateCurriculum(queryClient, programId) {
  queryClient.invalidateQueries({ queryKey: queryKeys.staff.programCurriculum(programId) });
  queryClient.invalidateQueries({ queryKey: [...queryKeys.staff.all, 'curriculum-impact'] });
  queryClient.invalidateQueries({ queryKey: queryKeys.staff.subjects() });
  queryClient.invalidateQueries({ queryKey: queryKeys.staff.programs() });
}
