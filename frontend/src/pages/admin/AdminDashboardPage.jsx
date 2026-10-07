import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { FiInbox, FiUserPlus, FiBarChart2, FiSettings, FiCheck, FiX } from 'react-icons/fi';
import { formatTime } from '../../lib/tools';
import { dashboardApi } from '../../lib/api/dashboardApi';
import BackupStatusCard from '../../components/admin/BackupStatusCard';
import { STATUS_LABELS } from '../../lib/reportExport';

const ROLE_ROWS = [
  ['admin', 'Administrators'],
  ['staff', 'Registrar staff'],
  ['student', 'Students'],
];

const thClass = 'py-2 px-3 text-left font-semibold text-gray-600 border-b border-gray-200';
const tdClass = 'py-2 px-3 border-b border-gray-100';

/** System-wide totals counted when the dashboard loads (#96). */
const SystemTotals = ({ totals }) => (
  <section className="mb-8" aria-labelledby="system-totals-title">
    <h3 id="system-totals-title" className="mb-4 text-lg font-semibold text-gray-800">System Totals</h3>
    <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <div className="p-5 rounded-xl bg-white border border-gray-100 shadow-[0_4px_14px_rgba(0,0,0,0.06)] lg:col-span-2">
        <h4 className="m-0 mb-3 text-sm font-medium text-gray-500">User accounts</h4>
        <div className="overflow-x-auto">
          <table className="w-full text-sm border-collapse" aria-label="User accounts by role">
            <thead>
              <tr>
                <th className={thClass}>Role</th>
                <th className={`${thClass} text-right`}>Active</th>
                <th className={`${thClass} text-right`}>Inactive</th>
                <th className={`${thClass} text-right`}>Total</th>
              </tr>
            </thead>
            <tbody>
              {ROLE_ROWS.map(([role, label]) => {
                const row = totals.users?.[role] || { active: 0, inactive: 0, total: 0 };
                return (
                  <tr key={role}>
                    <td className={tdClass}>{label}</td>
                    <td className={`${tdClass} text-right`}>{row.active}</td>
                    <td className={`${tdClass} text-right`}>{row.inactive}</td>
                    <td className={`${tdClass} text-right font-semibold`}>{row.total}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>
      <div className="flex flex-col gap-4">
        <div className="p-5 rounded-xl bg-white border border-gray-100 shadow-[0_4px_14px_rgba(0,0,0,0.06)]">
          <h4 className="m-0 mb-1 text-sm font-medium text-gray-500">Student records</h4>
          <p className="m-0 text-2xl font-bold text-gray-800">{totals.students}</p>
        </div>
        <div className="p-5 rounded-xl bg-white border border-gray-100 shadow-[0_4px_14px_rgba(0,0,0,0.06)]">
          <h4 className="m-0 mb-2 text-sm font-medium text-gray-500">Record requests</h4>
          <dl className="m-0 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
            {Object.entries(STATUS_LABELS).map(([status, label]) => (
              <React.Fragment key={status}>
                <dt className="text-gray-600">{label}</dt>
                <dd className="m-0 text-right font-semibold text-gray-800">{totals.requests?.[status] ?? 0}</dd>
              </React.Fragment>
            ))}
          </dl>
        </div>
      </div>
    </div>
  </section>
);

const AdminDashboardPage = () => {
  const [activity, setActivity] = useState([]);
  const [kpis, setKpis] = useState(null);
  const [totals, setTotals] = useState(null);

  useEffect(() => {
    dashboardApi.getDashboard()
      .then((res) => {
        setKpis(res?.kpis || null);
        setTotals(res?.totals || null);
        const acts = res?.recent_activity || [];
        const formatted = acts.map((a) => ({
          id: a.id,
          type: a.type,
          desc: a.desc,
          time: new Date(a.time).toLocaleString(),
          user: a.user,
        }));
  
        setActivity(formatted);
      })
      .catch(() => {});
  }, []);

  const quickLinks = [
    { to: '/admin/users', label: 'User Management', icon: FiUserPlus, color: 'indigo' },
    { to: '/admin/reports', label: 'Reports', icon: FiBarChart2, color: 'green' },
    { to: '/admin/settings', label: 'System Settings', icon: FiSettings, color: 'slate' },
    { to: '/staff/profile-updates', label: 'Profile Updates', icon: FiCheck, color: 'amber', count: kpis?.pending_profile_updates },
  ];

  const iconColorMap = {
    amber: 'bg-amber-100 text-amber-700',
    indigo: 'bg-indigo-100 text-indigo-700',
    green: 'bg-green-100 text-green-700',
    slate: 'bg-slate-100 text-slate-700',
  };

  const activityIcon = (type) => {
    switch (type) {
      case 'approve':
        return <FiCheck className="w-4 h-4 text-green-600" />;
      case 'reject':
        return <FiX className="w-4 h-4 text-red-600" />;
      default:
        return <FiInbox className="w-4 h-4 text-gray-600" />;
    }
  };

  return (
    <>
      <section className="mb-6">
        <h2 className="m-0 text-2xl font-bold text-gray-800">Admin Dashboard</h2>
        <p className="mt-1 m-0 text-gray-600 text-sm">System overview and quick access to key functions.</p>
      </section>

      <BackupStatusCard />

      {totals && <SystemTotals totals={totals} />}

      <section className="mb-8">
        <h3 className="mb-4 text-lg font-semibold text-gray-800">Quick Actions</h3>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          {quickLinks.map(({ to, label, icon: Icon, color, count }) => (
            <Link
              key={to}
              to={to}
              className="flex items-center gap-4 p-5 rounded-xl bg-white border border-gray-100 shadow-[0_4px_14px_rgba(0,0,0,0.06)] hover:border-tmcc/30 hover:shadow-md transition-all no-underline text-gray-800 relative"
            >
              <span className={`flex items-center justify-center w-12 h-12 rounded-xl ${iconColorMap[color]}`}>
                <Icon className="w-6 h-6" />
              </span>
              <span className="font-medium">{label}</span>
              {count !== undefined && count > 0 && (
                <span className="absolute top-3 right-3 bg-red-500 text-white text-xs font-bold px-2 py-0.5 rounded-full">
                  {count}
                </span>
              )}
            </Link>
          ))}
        </div>
      </section>

      <section className="bg-white rounded-xl shadow-[0_4px_14px_rgba(0,0,0,0.08)] border border-gray-100 overflow-hidden">
        <div className="p-6 border-b border-gray-100">
          <h3 className="mt-0 mb-2 text-lg font-semibold text-gray-800">Recent Activity</h3>
          <p className="m-0 text-sm text-gray-500">Last 5 system events.</p>
        </div>
        <ul className="divide-y divide-gray-100">
          {activity.map((item) => (
            <li key={item.id} className="flex items-center gap-4 px-6 py-4 hover:bg-gray-50/80">
              <span className="flex items-center justify-center w-9 h-9 rounded-full bg-gray-100 shrink-0">
                {activityIcon(item.type)}
              </span>
              <div className="flex-1 min-w-0">
                <p className="m-0 text-sm text-gray-800">{item.desc} - {item.user?.name ?? 'System'}</p>
                <p className="m-0 text-xs text-gray-500">{item.time}</p>
              </div>
            </li>
          ))}
        </ul>
      </section>
    </>
  );
};

export default AdminDashboardPage;
