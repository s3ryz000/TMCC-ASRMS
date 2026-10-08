import React from 'react';
import { staffApi } from '../../lib/api/staffApi';
import { queryKeys } from '../../lib/react-query/queryKeys';
import ActivityLogView from '../../components/logs/ActivityLogView';

/**
 * My Activity (#94): the signed-in registrar's own system log entries,
 * read-only, with the same filters as the admin log except the user.
 */
const StaffMyActivityPage = () => (
  <section className="sd-content">
    <h2 className="sd-section-title">My Activity</h2>
    <p className="sd-filter-hint mb-4">Everything you did in the system, as recorded in the system log. This list is read-only.</p>

    <ActivityLogView
      queryKey={(params) => queryKeys.staff.myActivity(params)}
      fetchPage={(params) => staffApi.getMyActivity(params)}
      emptyText="No activity recorded yet."
    />
  </section>
);

export default StaffMyActivityPage;
