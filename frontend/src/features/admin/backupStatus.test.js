import { describeBackupStatus, formatBytes } from './backupStatus';

const success = { at: '2026-10-05T18:00:00+08:00', file: 'asrms-20261005-1800.zip', folder: 'daily', bytes: 1536000 };
const base = {
  ok: true,
  stale: false,
  stale_after_hours: 26,
  last_success: success,
  last_run: { ...success, ok: true, label: 'daily', message: null },
  copies: { daily: 7, weekly: 4, monthly: 3, 'pre-update': 1, 'pre-restore': 0 },
  free_bytes: 120 * 1024 ** 3,
};

describe('Backups card (#65)', () => {
  test('a recent successful backup is green', () => {
    const card = describeBackupStatus(base);
    expect(card.tone).toBe('ok');
    expect(card.headline).toBe('Backups are working');
    expect(card.reason).toBeNull();
    expect(card.details[0]).toContain('asrms-20261005-1800.zip, 1.5 MB');
    expect(card.details[1]).toBe('Copies kept: 7 daily, 4 weekly, 3 monthly');
    expect(card.details[2]).toBe('Free space on the backup drive: 120.0 GB');
  });

  test('a forced failure turns the card red, even right after a success', () => {
    const card = describeBackupStatus({
      ...base,
      ok: false,
      last_run: { at: '2026-10-06T18:00:00+08:00', ok: false, label: 'daily', file: null, message: "Backup folder D:\\ASRMS-Backups\\daily can't be created." },
    });
    expect(card.tone).toBe('bad');
    expect(card.headline).toBe('Backups need attention');
    expect(card.reason).toContain("The last backup failed");
    expect(card.reason).toContain("can't be created");
    expect(card.details[0]).toContain('asrms-20261005-1800.zip');
  });

  test('a stale backup is red', () => {
    const card = describeBackupStatus({ ...base, ok: false, stale: true });
    expect(card.tone).toBe('bad');
    expect(card.reason).toBe('No successful backup in the last 26 hours.');
  });

  test('no backup yet, or no answer, is red', () => {
    expect(describeBackupStatus({ ...base, ok: false, stale: true, last_success: null, last_run: null }).reason)
      .toBe('No backup has completed yet.');
    expect(describeBackupStatus(null).tone).toBe('bad');
  });

  test('the server saying not ok is never shown green', () => {
    expect(describeBackupStatus({ ...base, ok: false }).tone).toBe('bad');
  });

  test('sizes', () => {
    expect(formatBytes(512)).toBe('512 bytes');
    expect(formatBytes(2048)).toBe('2.0 KB');
    expect(formatBytes(null)).toBe('unknown');
  });
});
