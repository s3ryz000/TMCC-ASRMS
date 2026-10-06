import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { FiAlertTriangle, FiCheckCircle, FiDatabase } from 'react-icons/fi';
import { adminApi } from '../../lib/api/adminApi';
import { queryKeys } from '../../lib/react-query/queryKeys';
import { describeBackupStatus } from '../../features/admin/backupStatus';

/** The admin dashboard's Backups card (#65): green when backups work, red when they need attention. */
const BackupStatusCard = () => {
  const { data, isLoading, isError } = useQuery({
    queryKey: queryKeys.admin.backupStatus(),
    queryFn: adminApi.getBackupStatus,
    staleTime: 60_000,
    refetchInterval: 10 * 60_000,
  });

  if (isLoading) {
    return (
      <section className="mb-8 p-5 rounded-xl bg-white border border-gray-100 text-sm text-gray-500">
        Checking backups…
      </section>
    );
  }

  const card = describeBackupStatus(isError ? null : data);
  const ok = card.tone === 'ok';
  const Icon = ok ? FiCheckCircle : FiAlertTriangle;

  return (
    <section
      className={`mb-8 p-5 rounded-xl border shadow-[0_4px_14px_rgba(0,0,0,0.06)] ${ok ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-300'}`}
      data-testid="backup-status-card"
      data-tone={card.tone}
    >
      <div className="flex items-start gap-4">
        <span className={`flex items-center justify-center w-12 h-12 rounded-xl shrink-0 ${ok ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
          <FiDatabase className="w-6 h-6" />
        </span>
        <div className="flex-1 min-w-0">
          <h3 className={`m-0 flex items-center gap-2 text-lg font-semibold ${ok ? 'text-green-800' : 'text-red-800'}`}>
            <Icon className="w-5 h-5" /> Backups: {card.headline}
          </h3>
          {card.reason && <p className="mt-1 mb-0 text-sm font-medium text-red-800 break-words">{card.reason}</p>}
          <ul className="mt-2 mb-0 pl-0 list-none text-sm text-gray-700 space-y-0.5">
            {card.details.map((line) => (
              <li key={line} className="break-words">{line}</li>
            ))}
          </ul>
          {!ok && (
            <p className="mt-2 mb-0 text-xs text-gray-600">
              Tell the system administrator today. The weekly check steps are in the admin guide (ADMIN-WEEKLY-CHECK).
            </p>
          )}
        </div>
      </div>
    </section>
  );
};

export default BackupStatusCard;
