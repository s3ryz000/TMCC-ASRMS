import { apiClient } from './client';

/** Phase 2: faculty, program coordinators and deans (role:faculty,coordinator,dean). */
export const academicApi = {
  getMe: async () => {
    const { data } = await apiClient.get('/academic/me');
    return data;
  },
};
