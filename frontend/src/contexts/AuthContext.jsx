import React, { createContext, useContext, useCallback, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import {
  useLoginMutation,
  useLogoutMutation,
  useCurrentUserQuery,
} from '../lib/react-query/authQueries';
import { getStoredAuth, clearStoredAuth } from '../lib/authStorage';
import { queryKeys } from '../lib/react-query/queryKeys';

const AuthContext = createContext(null);

export const useAuth = () => {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error('useAuth must be used within AuthProvider');
  }
  return ctx;
};

/**
 * Get the primary role from user (Spatie roles or user.role)
 */
export const getUserRole = (user) => {
  if (!user) return null;
  if (user.roles?.length) return user.roles[0].name;
  return user.role || null;
};

/**
 * Role-based route paths
 */
export const ROLE_ROUTES = {
  admin: '/admin',
  staff: '/staff',
  student: '/dashboard',
};

export const AuthProvider = ({ children }) => {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const loginMutation = useLoginMutation();
  const logoutMutation = useLogoutMutation();
  const { data: user, isLoading: isUserLoading } = useCurrentUserQuery();

  // On a 401 (the api client dispatches auth:logout) drop every cached record,
  // not just the user, so nothing of this session outlives it (#58). If the
  // session had expired (#86), go to the login page, which says so.
  useEffect(() => {
    const handler = (event) => {
      queryClient.clear();
      queryClient.setQueryData(queryKeys.auth.user(), null);
      if (event?.detail?.expired) {
        navigate('/', { replace: true, state: { sessionExpired: true } });
      }
    };
    window.addEventListener('auth:logout', handler);
    return () => window.removeEventListener('auth:logout', handler);
  }, [queryClient, navigate]);

  const { token } = getStoredAuth();
  const isAuthenticated = !!token && !!user;
  const role = getUserRole(user);

  const login = useCallback(
    async (credentials) => {
      const result = await loginMutation.mutateAsync(credentials);
      const userRole = getUserRole(result.user);
      const redirectPath = ROLE_ROUTES[userRole] || '/dashboard';
      navigate(redirectPath, { replace: true });
      return result;
    },
    [loginMutation, navigate]
  );

  const logout = useCallback(async () => {
    try {
      await logoutMutation.mutateAsync();
    } catch {
      clearStoredAuth();
      queryClient.clear();
      queryClient.setQueryData(queryKeys.auth.user(), null);
    }
    navigate('/', { replace: true });
  }, [logoutMutation, navigate, queryClient]);

  const value = {
    user,
    role,
    isAuthenticated,
    isLoading: isUserLoading,
    login,
    logout,
    loginMutation,
    logoutMutation,
  };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
};
