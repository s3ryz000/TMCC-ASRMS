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
    backupStatus: () => [...queryKeys.admin.all, 'backupStatus'],
  },
  student: {
    all: ['student'],
    profileUpdates: () => [...queryKeys.student.all, 'profile-updates'],
  },
  staff: {
    all: ['staff'],
    pendingRequests: () => [...queryKeys.staff.all, 'pending-requests'],
    approvedForRelease: () => [...queryKeys.staff.all, 'approved-release'],
    studentsList: (filters) => [...queryKeys.staff.all, 'students', filters],
    studentDetail: (id) => [...queryKeys.staff.all, 'student', id],
    studentAcademicRecord: (id) => [...queryKeys.staff.all, 'student-academic-record', id],
    studentDocuments: (id) => [...queryKeys.staff.all, 'student-documents', id],
    programs: () => [...queryKeys.staff.all, 'programs'],
    programCurriculum: (programId) => [...queryKeys.staff.all, 'program-curriculum', String(programId)],
    curriculumImpact: (entryId) => [...queryKeys.staff.all, 'curriculum-impact', String(entryId)],
    subjects: () => [...queryKeys.staff.all, 'subjects'],
    subjectPrefixes: () => [...queryKeys.staff.all, 'subject-prefixes'],
    studentNumberCheck: (number) => [...queryKeys.staff.all, 'student-number-check', number],
    studentNumberMismatches: () => [...queryKeys.staff.all, 'student-number-mismatches'],
  },
};
