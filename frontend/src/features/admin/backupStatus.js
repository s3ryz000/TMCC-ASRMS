/**
 * What the admin's Backups card says (#65), from GET /admin/backups/status.
 * Green only when the last run succeeded and the last success is recent
 * (the server decides "recent": 26 hours).
 */

export function formatBytes(bytes) {
  if (bytes === null || bytes === undefined || Number.isNaN(Number(bytes))) return 'unknown';
  const units = ['bytes', 'KB', 'MB', 'GB', 'TB'];
  let value = Number(bytes);
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit += 1;
  }
  return unit === 0 ? `${value} bytes` : `${value.toFixed(1)} ${units[unit]}`;
}

function formatWhen(iso) {
  if (!iso) return 'unknown';
  const date = new Date(iso);
  return Number.isNaN(date.getTime())
    ? 'unknown'
    : date.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' });
}

export function describeBackupStatus(status) {
  if (!status) {
    return { tone: 'bad', headline: 'Backup status unavailable', reason: 'The backup status could not be loaded.', details: [] };
  }

  const { last_success: success, last_run: run, copies = {} } = status;
  let reason = null;
  if (run && !run.ok) {
    reason = `The last backup failed (${formatWhen(run.at)}): ${run.message || 'no reason recorded'}`;
  } else if (!success) {
    reason = 'No backup has completed yet.';
  } else if (status.stale) {
    reason = `No successful backup in the last ${status.stale_after_hours} hours.`;
  }

  const details = [
    success
      ? `Last successful backup: ${formatWhen(success.at)} (${success.file}, ${formatBytes(success.bytes)})`
      : 'Last successful backup: none',
    `Copies kept: ${copies.daily ?? 0} daily, ${copies.weekly ?? 0} weekly, ${copies.monthly ?? 0} monthly`,
    `Free space on the backup drive: ${formatBytes(status.free_bytes)}`,
  ];

  return status.ok && !reason
    ? { tone: 'ok', headline: 'Backups are working', reason: null, details }
    : { tone: 'bad', headline: 'Backups need attention', reason: reason || 'The server reports a problem with the backups.', details };
}
