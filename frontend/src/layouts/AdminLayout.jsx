import React, { useState, useEffect, useCallback } from 'react';
import { Link, Outlet, useLocation } from 'react-router-dom';
import {
  FiInbox,
  FiUsers,
  FiBarChart2,
  FiKey,
  FiLogOut,
  FiPackage,
  FiChevronRight,
  FiUser,
  FiGrid,
  FiCheckCircle,
  FiSettings,
  FiUserPlus,
  FiClock
} from 'react-icons/fi';
import { useAuth } from '../contexts/AuthContext';
import ChangePasswordModal from '../components/staff/ChangePasswordModal';
import { useCurrentTermQuery } from '../hooks/useCurrentTermQuery';
import { dashboardApi } from '../lib/api/dashboardApi';



const AdminLayout = () => {
  const { user, logout, logoutMutation } = useAuth();
  const location = useLocation();
  const [currentTime, setCurrentTime] = useState('');
  const [currentDate, setCurrentDate] = useState('');
  const [logoError, setLogoError] = useState(false);
  const [changePasswordOpen, setChangePasswordOpen] = useState(false);
  const [kpi, setKpi] = useState({
    pendingRequests: 0,
    processedToday: 0,
    studentsCount: 0,
    documentsReleased: 0,
  });
  const { data: term, isLoading: termLoading } = useCurrentTermQuery();
  
  useEffect(() => {
    const updateDateTime = () => {
      const now = new Date();
  
      setCurrentDate(
        now.toLocaleDateString('en-US', {
          weekday: 'long',
          year: 'numeric',
          month: 'long',
          day: 'numeric',
        })
      );
  
      setCurrentTime(
        now.toLocaleTimeString('en-US', {
          hour: '2-digit',
          minute: '2-digit',
          second: '2-digit',
          hour12: true,
        })
      );
    };
  
    updateDateTime();
    const interval = setInterval(updateDateTime, 1000);
  
    return () => clearInterval(interval);
  }, []);
    const fetchKpis = useCallback(() => {
      dashboardApi.getDashboard()
        .then((res) => {
          const k = res?.kpis || {};
          setKpi({
            pendingRequests: k.pending_requests ?? 0,
            processedToday: k.processed_today ?? 0,
            studentsCount: k.students_count ?? 0,
            documentsReleased: k.documents_released_today ?? 0,
          });
        })
        .catch(() => {});
    }, []);
    useEffect(() => {
      fetchKpis();
    }, [location.pathname, fetchKpis]);
    
  const pathname = location.pathname;
  const isNewStudentPage = pathname === '/admin/students/new';

  const isRegistrarRoute =
    pathname.startsWith('/admin/students') || pathname.startsWith('/admin/view-records');
  const [registrarOpen, setRegistrarOpen] = useState(isRegistrarRoute);

  useEffect(() => {
    if (isRegistrarRoute) setRegistrarOpen(true);
  }, [isRegistrarRoute, pathname]);

  const navItemsBeforeRegistrar = [
    { id: 'dashboard', label: 'Dashboard', icon: FiGrid, path: '/admin' },
    { id: 'users', label: 'User Management', icon: FiUserPlus, path: '/admin/users' },
  ];


  const navItemsAfterRegistrar = [
    { id: 'reports', label: 'Reports', icon: FiBarChart2, path: '/admin/reports' },
    { id: 'settings', label: 'System Settings', icon: FiSettings, path: '/admin/settings' },
    { id: 'logs', label: 'System Logs', icon: FiClock, path: '/admin/logs' },
  ];

  const renderNavLink = (item) => {
    const { id, label, icon: Icon, path } = item;
    const isActive =
      !isNewStudentPage &&
      (pathname === path || (path === '/admin/students' && pathname.startsWith('/admin/students')));
    return (
      <Link
        key={id}
        to={path}
        className={`flex items-center gap-3 w-full py-3 px-4 rounded-lg text-[0.95rem] text-left no-underline transition-colors ${
          isActive ? 'bg-white/25 text-white font-semibold' : 'text-white/95 hover:bg-white/15 hover:text-white'
        }`}
        aria-current={isActive ? 'page' : undefined}
      >
        <Icon className="w-5 h-5 shrink-0" aria-hidden />
        <span className="flex-1">{label}</span>
        <FiChevronRight className="w-4 h-4 shrink-0 opacity-80" />
      </Link>
    );
  };

  const handleChangePassword = () => setChangePasswordOpen(true);

  const adminName = user?.name || user?.username || 'Administrator';

  return (
    <div className="flex min-h-screen bg-[#f4f6f8]">
      <aside className="w-[260px] min-w-[260px] sticky top-0 h-screen flex flex-col bg-[#1ac76a] text-white shadow-[2px_0_12px_rgba(0,0,0,0.08)]">
        <div className="px-5 py-6 border-b border-white/15 flex items-center gap-3 shrink-0">
          <div className="w-10 h-10 rounded-full bg-white flex items-center justify-center shrink-0 overflow-hidden">
            {!logoError ? (
              <img
                src="/logo.png"
                alt="TMCC Logo"
                className="w-full h-full object-contain"
                onError={() => setLogoError(true)}
              />
            ) : (
              <span className="text-sm font-bold text-tmcc">TMCC</span>
            )}
          </div>
          <div className="min-w-0">
            <h1 className="m-0 text-base font-semibold tracking-wide truncate">TMCC</h1>
            <span className="text-xs opacity-90">Admin — ASRMS</span>
          </div>
        </div>

        <nav className="flex-1 min-h-0 overflow-y-auto py-4 px-3 flex flex-col gap-0.5" aria-label="Admin dashboard navigation">
          {navItemsBeforeRegistrar.map(renderNavLink)}
          {navItemsAfterRegistrar.map(renderNavLink)}
        </nav>

        <div className="p-4 pt-4 border-t border-white/15 shrink-0">
          <div className="text-xs mb-3 leading-snug text-white/90">
            <span className="block font-medium">{currentDate}</span>
            <span>{currentTime}</span>
          </div>
          <div className="flex flex-col gap-2">
            <button
              type="button"
              className="flex items-center gap-2 py-2 px-3 w-full justify-start rounded-md text-sm bg-white/15 border border-white/25 text-white hover:bg-white/25 transition-colors"
              onClick={handleChangePassword}
              aria-label="Change Password"
            >
              <FiKey className="w-4 h-4" />
              Change Password
            </button>
            <button
              type="button"
              className="flex items-center gap-2 py-2 px-3 w-full justify-start rounded-md text-sm bg-white/15 border border-white/25 text-white hover:bg-red-500/40 hover:border-red-500/50 transition-colors disabled:opacity-70 disabled:cursor-not-allowed"
              onClick={() => logout()}
              disabled={logoutMutation.isPending}
              aria-label="Sign out"
            >
              <FiLogOut className="w-4 h-4" />
              {logoutMutation.isPending ? 'Signing out...' : 'Sign out'}
            </button>
          </div>
        </div>
      </aside>

      <div className="flex-1 flex flex-col min-w-0">
        <header className="bg-white border-b border-gray-200 px-6 py-3 flex items-center justify-between shrink-0 shadow-[0_2px_8px_rgba(0,0,0,0.08)]">
          <div className="flex items-center gap-2">
            <span className="inline-flex items-center rounded-full overflow-hidden">
              <span className="bg-staff-sy-yellow text-gray-900 px-3 py-1 text-sm font-medium">
                {termLoading ? '…' : (term?.academic_year ?? '—')}
              </span>
              <span className="bg-staff-sy-green text-white px-3 py-1 text-sm font-medium">
                {termLoading ? '…' : (term?.semester ?? '—')}
              </span>
            </span>
            <span className="text-sm text-gray-500 ml-1">Active S.Y.</span>
          </div>
          <div className="flex items-center gap-4">
            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-green-100 text-tmcc text-xs font-medium">
              Admin
            </span>
            <span className="text-sm font-medium text-gray-700">{adminName}</span>
            <div className="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center">
              <FiUser className="w-4 h-4 text-gray-600" />
            </div>
          </div>
        </header>

        <div className="bg-white border-b border-gray-100 px-6 py-4 shrink-0">
          <div className="max-w-[1100px] mx-auto grid grid-cols-2 sm:grid-cols-4 gap-4">
           
            <div className="flex items-center gap-4 p-4 rounded-xl bg-gray-50 border border-gray-100">
              <span className="flex items-center justify-center w-12 h-12 rounded-xl bg-green-100 text-green-700">
                <FiCheckCircle className="w-6 h-6" />
              </span>
              <div>
                <p className="m-0 text-2xl font-bold text-gray-900">{kpi.processedToday}</p>
                <p className="m-0 text-xs font-medium text-gray-500 uppercase tracking-wider">Processed Today</p>
              </div>
            </div>
            {/* Plain counter: admins have no student-records page (read-only role, §1.4). */}
            <div className="flex items-center gap-4 p-4 rounded-xl bg-gray-50 border border-gray-100">
              <span className="flex items-center justify-center w-12 h-12 rounded-xl bg-blue-100 text-blue-700">
                <FiUsers className="w-6 h-6" />
              </span>
              <div>
                <p className="m-0 text-2xl font-bold text-gray-900">{kpi.studentsCount}</p>
                <p className="m-0 text-xs font-medium text-gray-500 uppercase tracking-wider">Students</p>
              </div>
            </div>
            
          </div>
        </div>

        <main className="flex-1 overflow-auto py-6 px-8">
          <div className="max-w-[1100px] mx-auto">
            <Outlet />
          </div>
        </main>
      </div>

      <ChangePasswordModal isOpen={changePasswordOpen} onClose={() => setChangePasswordOpen(false)} />
    </div>
  );
};

export default AdminLayout;
