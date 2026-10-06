import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { authApi } from '../api/authApi';
import { queryKeys } from './queryKeys';
import { getStoredAuth, setStoredAuth, clearStoredAuth } from '../authStorage';

export { getStoredAuth, setStoredAuth, clearStoredAuth };

/**
 * Login mutation
 */
export const useLoginMutation = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (credentials) => authApi.login(credentials),
    onSuccess: (data) => {
      setStoredAuth(data.token, data.user);
      queryClient.setQueryData(queryKeys.auth.user(), data.user);
    },
  });
};

/**
 * Logout mutation
 */
export const useLogoutMutation = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => authApi.logout(),
    onSuccess: () => {
      clearStoredAuth();
      // Drop every cached record so the next user of this tab never sees it (#58).
      queryClient.clear();
      queryClient.setQueryData(queryKeys.auth.user(), null);
    },
  });
};

/**
 * Current user query (fetches from API if token exists)
 */
export const useCurrentUserQuery = (options = {}) => {
  const { token } = getStoredAuth();

  return useQuery({
    queryKey: queryKeys.auth.user(),
    queryFn: () => authApi.getCurrentUser(),
    enabled: !!token,
    staleTime: 5 * 60 * 1000, // 5 minutes
    retry: false, // avoid long loading when backend is unreachable (timeout already in apiClient)
    initialData: () => {
      const { user } = getStoredAuth();
      return user ?? undefined;
    },
    ...options,
  });
};
