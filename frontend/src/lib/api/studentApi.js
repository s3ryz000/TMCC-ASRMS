import { apiClient } from './client';

/**
 * All endpoints require auth:sanctum and role:student.
 */
export const studentApi = {
  /** Get authenticated student's profile (student + program + academic year/semester). */
  getProfile: async () => {
    const { data } = await apiClient.get('/student/profile');
    return data;
  },

  getAcademicSummary: async () => {
    const { data } = await apiClient.get('/student/academic-summary');
    return data;
  },

  /** Update authenticated student's SIS/SIUF fields (own record only). */
  updateSIS: async (payload) => {
    if (payload instanceof FormData) {
      payload.append('_method', 'PUT');
      const { data } = await apiClient.post('/student/sis', payload, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      return data;
    }
    const { data } = await apiClient.put('/student/sis', payload);
    return data;
  },

  /** The student's own profile update requests, newest first (#88). */
  getProfileUpdates: async () => {
    const { data } = await apiClient.get('/student/profile-updates');
    return data;
  },

  /** Correct a request returned for revision and send it back (FormData: SIS fields + supporting_document?). */
  resubmitProfileUpdate: async (id, payload) => {
    const { data } = await apiClient.post(`/student/profile-updates/${id}/resubmit`, payload, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    return data;
  },

  /** The student's own notifications, newest first (paginated) with unread_count (#89). */
  getNotifications: async (params = {}) => {
    const { data } = await apiClient.get('/student/notifications', { params });
    return data;
  },

  markNotificationRead: async (id) => {
    const { data } = await apiClient.patch(`/student/notifications/${id}/read`);
    return data;
  },

  markAllNotificationsRead: async () => {
    const { data } = await apiClient.patch('/student/notifications/read-all');
    return data;
  },

  getSubjects: async (params = {}) => {
    const { data } = await apiClient.get('/student/subjects', { params });
    return data;
  },

  /** Grades. Params: academic_year?, semester? */
  getGrades: async (params = {}) => {
    const { data } = await apiClient.get('/student/grades', { params });
    return data;
  },

  /** List authenticated student's record requests (paginated). */
  getRecordRequests: async (params = {}) => {
    const { data } = await apiClient.get('/student/record-requests', { params });
    return data;
  },

  /** Submit a new record request. Payload: { record_type, purpose?, copies? } */
  createRecordRequest: async (payload) => {
    const { data } = await apiClient.post('/student/record-requests', payload);
    return data;
  },

  /** Get a single record request by id (own only). */
  getRecordRequest: async (id) => {
    const { data } = await apiClient.get(`/student/record-requests/${id}`);
    return data;
  },

  downloadApprovalSlip: async (id) => {
    const response = await apiClient.get(`/student/record-requests/${id}/approval-slip`, {
      responseType: 'blob',
    });
    return response;
  },

  /** The semesters with grades, for the unofficial report card (#34). */
  getReportCardTerms: async () => {
    const { data } = await apiClient.get('/student/report-card/terms');
    return data;
  },

  /** The student's own unofficial report card for one semester, as a PDF (#34). */
  downloadReportCard: async ({ academic_year, semester }) => {
    const response = await apiClient.get('/student/report-card', {
      params: { academic_year, semester },
      responseType: 'blob',
    });
    return response;
  },

  downloadTranscript: async (id) => {
    const response = await apiClient.get(`/student/record-requests/${id}/transcript`, {
      responseType: 'blob',
    });
    return response;
  },
};
