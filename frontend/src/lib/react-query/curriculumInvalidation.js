import { queryKeys } from './queryKeys';

/**
 * Refetch what a curriculum edit (place, move, remove) changes: the program's
 * curriculum, the subject list (each subject's curriculum count) and the
 * program list (curriculum counts).
 */
export function invalidateCurriculum(queryClient, programId) {
  queryClient.invalidateQueries({ queryKey: queryKeys.staff.programCurriculum(programId) });
  queryClient.invalidateQueries({ queryKey: queryKeys.staff.subjects() });
  queryClient.invalidateQueries({ queryKey: queryKeys.staff.programs() });
}
