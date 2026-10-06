import React from 'react';
import StaffEditStudentHubPage from '../staff/StaffEditStudentHubPage';

// Not routed (admins use the read-only staff pages); kept pointing at the
// Edit Student hub (#55) so it still builds.
const AdminEditStudentPage = () => <StaffEditStudentHubPage />;

export default AdminEditStudentPage;
