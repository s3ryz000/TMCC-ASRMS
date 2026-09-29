export const queryKeys = {
  auth: {
    all: ['auth'],
    user: () => [...queryKeys.auth.all, 'user'],
  },
  settings: {
    all: ['settings'],
    currentTerm: () => [...queryKeys.settings.all, 'currentTerm'],
  },
  admin: {
    all: ['admin'],
    usersList: (filters) => [...queryKeys.admin.all, 'users', filters],
    settings: () => [...queryKeys.admin.all, 'systemSettings'],
    logs: () => [...queryKeys.admin.all, 'systemLogs'],
    logsList: (filters) => [...queryKeys.admin.all, 'systemLogs', filters],
  },
  staff: {
    all: ['staff'],
    pendingRequests: () => [...queryKeys.staff.all, 'pending-requests'],
    approvedForRelease: () => [...queryKeys.staff.all, 'approved-release'],
    studentsList: (filters) => [...queryKeys.staff.all, 'students', filters],
    studentDetail: (id) => [...queryKeys.staff.all, 'student', id],
    programs: () => [...queryKeys.staff.all, 'programs'],
    subjects: () => [...queryKeys.staff.all, 'subjects'],
    reports: () => [...queryKeys.staff.all, 'reports'],
  },
};
