import React, { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { FiBell } from 'react-icons/fi';
import { studentApi } from '../../lib/api/studentApi';
import { queryKeys } from '../../lib/react-query/queryKeys';

/** Portal pages a notification may open; anything else stays on the current page. */
const PORTAL_PAGES = ['/dashboard/request', '/dashboard/sis'];

/** "2026-10-08 09:30:00" (office time) → "Oct 8, 9:30 AM". */
const formatWhen = (value) => (value
  ? new Date(value.replace(' ', 'T')).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
  : '');

/**
 * The student portal bell (#89): unread count and the latest notifications.
 * Opening one marks it read and goes to the page it is about. Refreshed every
 * minute while the portal is open (no e-mail on the LAN).
 */
const NotificationBell = () => {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const wrapperRef = useRef(null);

  const { data, isLoading, isError } = useQuery({
    queryKey: queryKeys.student.notifications(),
    queryFn: () => studentApi.getNotifications(),
    refetchInterval: 60000,
  });
  const items = data?.data ?? [];
  const unread = data?.unread_count ?? 0;

  const refresh = () => queryClient.invalidateQueries({ queryKey: queryKeys.student.notifications() });
  const markRead = useMutation({ mutationFn: studentApi.markNotificationRead, onSettled: refresh });
  const markAll = useMutation({ mutationFn: studentApi.markAllNotificationsRead, onSettled: refresh });

  useEffect(() => {
    if (!open) return undefined;
    const onClick = (e) => {
      if (wrapperRef.current && !wrapperRef.current.contains(e.target)) setOpen(false);
    };
    const onKey = (e) => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onClick);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onClick);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  const openItem = (item) => {
    if (!item.read_at) markRead.mutate(item.id);
    if (item.kind === 'profile_update') {
      queryClient.invalidateQueries({ queryKey: queryKeys.student.profileUpdates() });
    }
    setOpen(false);
    if (PORTAL_PAGES.includes(item.link)) navigate(item.link);
  };

  return (
    <div className="relative" ref={wrapperRef}>
      <button
        type="button"
        className="sd-link-btn relative"
        onClick={() => setOpen((o) => !o)}
        aria-haspopup="true"
        aria-expanded={open}
        aria-label={unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'}
      >
        <FiBell className="sd-icon" />
        Notifications
        {unread > 0 && (
          <span className="ml-1 inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1 rounded-full bg-red-600 text-white text-xs font-bold leading-none">
            {unread > 99 ? '99+' : unread}
          </span>
        )}
      </button>

      {open && (
        <div
          className="absolute right-0 mt-2 z-50 w-[min(92vw,22rem)] bg-white text-gray-800 rounded-lg shadow-lg border border-gray-200 overflow-hidden"
          role="dialog"
          aria-label="Notifications"
        >
          <div className="flex items-center justify-between px-4 py-2 border-b border-gray-100">
            <span className="font-semibold text-sm">Notifications</span>
            {unread > 0 && (
              <button
                type="button"
                onClick={() => markAll.mutate()}
                disabled={markAll.isPending}
                className="text-xs font-semibold text-indigo-700 hover:underline disabled:opacity-50"
              >
                Mark all as read
              </button>
            )}
          </div>

          <div className="max-h-[60vh] overflow-y-auto">
            {isLoading ? (
              <p className="px-4 py-6 text-sm text-gray-500 m-0">Loading…</p>
            ) : isError ? (
              <p className="px-4 py-6 text-sm text-red-700 m-0" role="alert">Could not load notifications.</p>
            ) : items.length === 0 ? (
              <p className="px-4 py-6 text-sm text-gray-500 m-0">No notifications yet.</p>
            ) : (
              <ul className="m-0 p-0 list-none divide-y divide-gray-100">
                {items.map((item) => (
                  <li key={item.id}>
                    <button
                      type="button"
                      onClick={() => openItem(item)}
                      className={`w-full text-left px-4 py-3 hover:bg-gray-50 flex gap-2 ${item.read_at ? '' : 'bg-indigo-50/60'}`}
                    >
                      <span
                        className={`mt-1.5 h-2 w-2 rounded-full flex-shrink-0 ${item.read_at ? 'bg-transparent' : 'bg-indigo-600'}`}
                        aria-hidden
                      />
                      <span className="flex-1 min-w-0">
                        <span className={`block text-sm break-words ${item.read_at ? 'text-gray-700' : 'text-gray-900 font-semibold'}`}>
                          {item.message}
                        </span>
                        <span className="block text-xs text-gray-500 mt-0.5">
                          {formatWhen(item.created_at)}{item.read_at ? '' : ' · New'}
                        </span>
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      )}
    </div>
  );
};

export default NotificationBell;
