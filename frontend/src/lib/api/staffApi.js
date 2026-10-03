import { apiClient } from './client';

/**
 * Staff dashboard API — for record requests, student lookup, document release, reports.
 * All endpoints require auth:sanctum and staff/admin role on backend.
 */
export const staffApi = {
  getPendingRequests: async (params = {}) => {
    const { data } = await apiClient.get('/staff/pending-requests', { params });
    return data;
  },

  getApprovedRequests: async (params = {}) => {
    const { data } = await apiClient.get('/staff/approved-release', { params });
    return data;
  },

  getRejectedRequests: async (params = {}) => {
    const { data } = await apiClient.get('/staff/rejected-requests', { params });
    return data;
  },

  approveRequest: async (id, payload = {}) => {
    const { data } = await apiClient.patch(`/staff/requests/${id}/approve`, payload);
    return data;
  },

  rejectRequest: async (id, payload = {}) => {
    const { data } = await apiClient.patch(`/staff/requests/${id}/reject`, payload);
    return data;
  },

  getApprovedForRelease: async (params = {}) => {
    const { data } = await apiClient.get('/staff/approved-release', { params });
    return data;
  },

  getPendingProfileUpdates: async () => {
    const { data } = await apiClient.get('/staff/pending-profile-updates');
    return data;
  },

  approveProfileUpdate: async (id) => {
    const { data } = await apiClient.patch(`/staff/pending-profile-updates/${id}/approve`);
    return data;
  },

  rejectProfileUpdate: async (id, payload = {}) => {
    const { data } = await apiClient.patch(`/staff/pending-profile-updates/${id}/reject`, payload);
    return data;
  },

  downloadProfileUpdateDocument: async (id) => {
    const response = await apiClient.get(`/staff/pending-profile-updates/${id}/supporting-document`, {
      responseType: 'blob',
    });
    return response;
  },

  releaseDocument: async (requestId) => {
    const { data } = await apiClient.post('/staff/transactions', {
      request_id: requestId,
      transaction_type: 'release',
    });
    return data;
  },

  downloadTranscriptTemplate: async (requestId) => {
    const response = await apiClient.get(`/staff/requests/${requestId}/transcript-template`, {
      responseType: 'blob',
    });
    return response;
  },

  getAppointmentSlots: async (params = {}) => {
    const { data } = await apiClient.get('/staff/appointment-slots', { params });
    return data;
  },

  downloadApprovalSlip: async (requestId) => {
    const response = await apiClient.get(`/staff/requests/${requestId}/approval-slip`, {
      responseType: 'blob',
    });
    return response;
  },

  getStudents: async (params = {}) => {
    const { data } = await apiClient.get('/staff/students', { params });
    return data;
  },

  /**
   * Program list for staff filters (course dropdown). Cached client-side via React Query.
   * Archived programs are left out unless params.include_archived is 1.
   */
  getPrograms: async (params = {}) => {
    const { data } = await apiClient.get('/staff/programs', { params });
    return data;
  },

  /**
   * A program's curriculum rows plus live `totals` (#26):
   * { maximum_units, terms: [{ year_level, semester, units, subjects, over_max }],
   *   years: [{ year_level, units, subjects }], program: { units, subjects } }.
   */
  getProgramCurriculum: async (programId) => {
    const { data } = await apiClient.get(`/staff/programs/${programId}/curriculum`);
    return data;
  },

  getStudentById: async (id) => {
    const { data } = await apiClient.get(`/staff/students/${id}`);
    return data;
  },

  /** Official transcript PDF for a student (staff/admin). */
  downloadStudentTranscript: async (studentId) => {
    const response = await apiClient.get(`/staff/students/${studentId}/transcript`, {
      responseType: 'blob',
    });
    return response;
  },

  getReportsSummary: async () => {
    const { data } = await apiClient.get('/staff/reports/summary');
    return data;
  },

  getTransactionHistory: async (params = {}) => {
    const { data } = await apiClient.get('/staff/reports/transaction-history', { params });
    return data;
  },

  /**
   * Create a new student (staff/admin only).
   * Payload: student_number, first_name, last_name, date_of_birth, email,
   * contact_number?, address?, enrollment_date, graduation_date?, GPA?
   */
  createStudent: async (payload) => {
    const { data } = await apiClient.post('/staff/students', payload);
    return data;
  },

  /**
   * Update an existing student (staff/admin only).
   */
  updateStudent: async (id, payload) => {
    const { data } = await apiClient.put(`/staff/students/${id}`, payload);
    return data;
  },

  /** Change student's active program. Requires: new_program_id, reason. Optional: remarks. */
  updateStudentProgram: async (studentId, payload) => {
    const { data } = await apiClient.patch(`/staff/students/${studentId}/program`, payload);
    return data;
  },

  /** Fetch curriculum subjects for a program filtered by year_level and semester */
  getProgramCurriculumFiltered: async (programId, yearLevel, semester) => {
    const { data } = await apiClient.get(`/staff/programs/${programId}/curriculum`, {
      params: { year_level: yearLevel, semester },
    });
    return data;
  },

  /**
   * Subject catalogue with usage counts (staff/admin).
   * Archived subjects are left out unless params.include_archived is 1.
   * Each subject lists `programs` (codes whose curriculum uses it). Optional
   * params.search (code or title) and params.per_page / params.page; paging
   * adds `meta` { current_page, per_page, total, last_page } (#25).
   */
  getSubjects: async (params = {}) => {
    const { data } = await apiClient.get('/staff/subjects', { params });
    return data;
  },

  /** CHED subject code prefixes ("TPC - Tourism Professional Core") with subject counts (staff/admin). */
  getSubjectPrefixes: async () => {
    const { data } = await apiClient.get('/staff/subject-prefixes');
    return data;
  },

  /** Create, edit or delete a subject (registrar staff). */
  createSubject: async (payload) => {
    const { data } = await apiClient.post('/staff/subjects', payload);
    return data;
  },
  updateSubject: async (id, payload) => {
    const { data } = await apiClient.put(`/staff/subjects/${id}`, payload);
    return data;
  },
  deleteSubject: async (id) => {
    const { data } = await apiClient.delete(`/staff/subjects/${id}`);
    return data;
  },
  archiveSubject: async (id) => {
    const { data } = await apiClient.patch(`/staff/subjects/${id}/archive`);
    return data;
  },
  unarchiveSubject: async (id) => {
    const { data } = await apiClient.patch(`/staff/subjects/${id}/unarchive`);
    return data;
  },

  /** Create, edit or delete a program (registrar staff). */
  createProgram: async (payload) => {
    const { data } = await apiClient.post('/staff/programs', payload);
    return data;
  },
  updateProgram: async (id, payload) => {
    const { data } = await apiClient.put(`/staff/programs/${id}`, payload);
    return data;
  },
  deleteProgram: async (id) => {
    const { data } = await apiClient.delete(`/staff/programs/${id}`);
    return data;
  },
  archiveProgram: async (id) => {
    const { data } = await apiClient.patch(`/staff/programs/${id}/archive`);
    return data;
  },
  unarchiveProgram: async (id) => {
    const { data } = await apiClient.patch(`/staff/programs/${id}/unarchive`);
    return data;
  },

  // ── Curriculum builder (registrar staff, #24) ──
  // After any of these, call invalidateCurriculum(queryClient, programId).

  /**
   * Create a program with its curriculum in one transaction (#70).
   * Payload: { program: { code, name, description? },
   *   entries: [{ subject_id | new_subject: { code, title, units, description? }, year_level, semester }] }.
   * 422 errors are keyed by entry index (entries.3.subject_id); nothing is saved on error.
   */
  createCurriculum: async (payload) => {
    const { data } = await apiClient.post('/staff/curriculums', payload);
    return data;
  },

  /** Place a subject in a program. Payload: subject_id, year_level (1-4), semester (1 or 2). */
  addCurriculumEntry: async (programId, payload) => {
    const { data } = await apiClient.post(`/staff/programs/${programId}/curriculum`, payload);
    return data;
  },
  /** Move an entry to another term. Payload: year_level (1-4), semester (1 or 2). */
  moveCurriculumEntry: async (entryId, payload) => {
    const { data } = await apiClient.patch(`/staff/curriculum/${entryId}`, payload);
    return data;
  },
  /**
   * Remove an entry. Move and remove answer 409 when students of the program
   * already have records for the subject (#28), and remove also when another
   * entry lists its subject as a prerequisite.
   */
  removeCurriculumEntry: async (entryId) => {
    const { data } = await apiClient.delete(`/staff/curriculum/${entryId}`);
    return data;
  },
  /**
   * What an entry touches (staff/admin, #28): students with records (count,
   * up to 20 shown), enrollments/grades by status, entries requiring it,
   * can_move and can_remove.
   */
  getCurriculumImpact: async (entryId) => {
    const { data } = await apiClient.get(`/staff/curriculum/${entryId}/impact`);
    return data;
  },

  /** Add enrollment. Required: subject_id, academic_year, semester. Optional: status. */
  createEnrollment: async (studentId, payload) => {
    const { data } = await apiClient.post(`/staff/students/${studentId}/enrollments`, payload);
    return data;
  },

  updateEnrollment: async (studentId, enrollmentId, payload) => {
    const { data } = await apiClient.put(`/staff/students/${studentId}/enrollments/${enrollmentId}`, payload);
    return data;
  },

  deleteEnrollment: async (studentId, enrollmentId, payload = {}) => {
    // DELETE with body — axios supports this via `data` config key
    const { data } = await apiClient.delete(`/staff/students/${studentId}/enrollments/${enrollmentId}`, {
      data: payload,
    });
    return data;
  },

  /** Add grade. Required: subject_id, academic_year, semester. Optional: grade_value, remarks. */
  createGrade: async (studentId, payload) => {
    const { data } = await apiClient.post(`/staff/students/${studentId}/grades`, payload);
    return data;
  },

  updateGrade: async (studentId, gradeId, payload) => {
    const { data } = await apiClient.put(`/staff/students/${studentId}/grades/${gradeId}`, payload);
    return data;
  },

  deleteGrade: async (studentId, gradeId) => {
    const { data } = await apiClient.delete(`/staff/students/${studentId}/grades/${gradeId}`);
    return data;
  },

  archiveStudent: async (studentId, payload) => {
    const { data } = await apiClient.post(`/staff/students/${studentId}/archive`, payload);
    return data;
  },

  // ── Academic Progression endpoints ──

  /** Get full academic progress for a student (staff/admin). */
  getAcademicProgress: async (studentId) => {
    const { data } = await apiClient.get(`/staff/students/${studentId}/academic-progress`);
    return data;
  },

  getAcademicSummary: async (studentId) => {
    const { data } = await apiClient.get(`/staff/students/${studentId}/academic-summary`);
    return data.summary;
  },

  /** Add enrollment for the next allowed term. Backend computes term. */
  addNextTerm: async (studentId, payload) => {
    const { data } = await apiClient.post(`/staff/students/${studentId}/enrollments/add-next-term`, payload);
    return data;
  },

  /** Bulk update grades for enrolled subjects. */
  bulkUpdateGrades: async (studentId, payload) => {
    const { data } = await apiClient.put(`/staff/students/${studentId}/grades/bulk-update`, payload);
    return data;
  },
};
