# Weekly backup check (admin)

Do this **every Monday morning**. It takes about five minutes. If a backup is
broken, you will notice within a week at most, and usually the next day,
because the dashboard turns red.

## Every day: glance at the dashboard

Sign in as the admin. Look at the **Backups** card at the top of the dashboard:

- **Green, "Backups are working":** the last backup succeeded and is less than
  26 hours old.
- **Red, "Backups need attention":** the last backup failed, or none has
  succeeded in the last 26 hours. The red line says which. Tell the system
  administrator **the same day**.

## Every Monday: the weekly check

1. **Dashboard card.** It is green, and *Last successful backup* is from the
   previous evening, around 6:00 PM.
2. **Copies kept.** The card shows *7 daily*, up to *4 weekly* and up to
   *12 monthly*. In the first weeks after installation, fewer weekly and
   monthly copies is normal.
3. **Free space.** *Free space on the backup drive* is more than 10 times the
   size of the last backup. If it is less, tell the system administrator.
4. **System logs.** Open **System Logs** and search for `Backup`. There is one
   *Backup completed* entry for each evening of the past week, and no
   *Backup FAILED* entry. A failed entry gives the reason.
5. **Sign off.** Record the check in the backup check sheet below (or the
   office logbook): the date, *OK* or the problem found, and your initials.

| Date | Card green? | Last backup | Copies (d/w/m) | Free space | Failures this week | Initials |
|---|---|---|---|---|---|---|
| | | | | | | |

## When the card is red

Tell the system administrator, who checks on the server:

- `backend\storage\logs\backup.log` has one line per run, and a failed run says
  why. Common causes: the backup drive is disconnected or full, or the
  computer was off at 6:00 PM.
- In **Task Scheduler**, the task *TMCC ASRMS Daily Backup* exists and its last
  result is `0x0`.
- After fixing the cause, run the backup by hand in the `backend` folder:
  `php artisan asrms:backup`. Then reload the dashboard: the card must be
  green.

To put data back from a backup, follow `RESTORE.md`.

## Every 3 months: a restore drill

A backup is only proven by restoring it. Once a term, the system administrator
restores the latest backup on a spare computer or a copy, following
`RESTORE.md`. They then check one student's grades and transcript, and note
how long the restore took.
