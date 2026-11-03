import { apiClient } from './client';

/**
 * Admin API — users, system settings, reports export.
 * All endpoints require auth:sanctum and role:admin.
 */
export const adminApi = {
  // ---- Users ----
  getUsers: async (params = {}) => {
    const { data } = await apiClient.get('/admin/users', { params });
    return data;
  },

  getUser: async (id) => {
    const { data } = await apiClient.get(`/admin/users/${id}`);
    return data;
  },

  createUser: async (payload) => {
    const { data } = await apiClient.post('/admin/users', payload);
    return data;
  },

  updateUser: async (id, payload) => {
    const { data } = await apiClient.put(`/admin/users/${id}`, payload);
    return data;
  },

  // ---- Schools, programs, coordinators, faculty (Phase 2) ----
  getSchools: async () => {
    const { data } = await apiClient.get('/admin/schools');
    return data;
  },

  createSchool: async (payload) => {
    const { data } = await apiClient.post('/admin/schools', payload);
    return data;
  },

  updateSchool: async (id, payload) => {
    const { data } = await apiClient.put(`/admin/schools/${id}`, payload);
    return data;
  },

  archiveSchool: async (id) => {
    const { data } = await apiClient.patch(`/admin/schools/${id}/archive`);
    return data;
  },

  unarchiveSchool: async (id) => {
    const { data } = await apiClient.patch(`/admin/schools/${id}/unarchive`);
    return data;
  },

  assignProgramSchool: async (programId, schoolId) => {
    const { data } = await apiClient.put(`/admin/programs/${programId}/school`, { school_id: schoolId });
    return data;
  },

  setProgramCoordinators: async (programId, userIds) => {
    const { data } = await apiClient.put(`/admin/programs/${programId}/coordinators`, { user_ids: userIds });
    return data;
  },

  setFacultyProfile: async (userId, payload) => {
    const { data } = await apiClient.put(`/admin/faculty/${userId}/profile`, payload);
    return data;
  },

  // ---- System settings ----
  getSettings: async () => {
    const { data } = await apiClient.get('/admin/settings');
    return data;
  },

  updateSettings: async (payload) => {
    const { data } = await apiClient.put('/admin/settings', payload);
    return data;
  },

  // ---- Reports (#96): params { date_from, date_to } as YYYY-MM-DD ----
  exportReports: async (params = {}) => {
    const { data } = await apiClient.get('/admin/reports/export', { params });
    return data;
  },

  getRequestsReport: async (params = {}) => {
    const { data } = await apiClient.get('/admin/reports/requests', { params });
    return data;
  },

  getActivityReport: async (params = {}) => {
    const { data } = await apiClient.get('/admin/reports/activity', { params });
    return data;
  },

  // ---- System logs ----
  getSystemLogs: async (params = {}) => {
    const { data } = await apiClient.get('/admin/logs', { params });
    return data;
  },

  /** Same filters as getSystemLogs (#94): user_id, role, q, date_from, date_to. */
  exportSystemLogsPdf: async (params = {}) => {
    const { data } = await apiClient.get('/admin/logs/export-pdf', { params, responseType: 'blob' });
    return data;
  },

  /** The people who appear in the log, for the user filter. */
  getSystemLogUsers: async () => {
    const { data } = await apiClient.get('/admin/logs/users');
    return data;
  },

  // ---- Backups (#65) ----
  getBackupStatus: async () => {
    const { data } = await apiClient.get('/admin/backups/status');
    return data;
  },
};
