import { useQuery } from '@tanstack/react-query';
import { staffApi } from '../lib/api/staffApi';
import { queryKeys } from '../lib/react-query/queryKeys';

/**
 * One student, loaded from the API by id on the page that shows it (#55,
 * #57): refreshing or opening a direct link works, and nothing about the
 * student travels in router state, the URL or browser storage. Refetched
 * whenever a page mounts so an edit on another page is always reflected.
 */
export function useStudentQuery(studentId) {
  return useQuery({
    queryKey: queryKeys.staff.studentDetail(studentId),
    queryFn: () => staffApi.getStudentById(studentId),
    select: (res) => res?.student ?? res,
    enabled: studentId != null && studentId !== '',
    refetchOnMount: 'always',
  });
}
