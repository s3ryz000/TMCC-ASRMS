# Restoring TMCC ASRMS from a backup

Use this when the database or the uploaded documents are lost or damaged, or to
undo a bad update. Expect 10 to 15 minutes, most of it reading. The restore
itself takes under a minute.

**Rule:** a restore replaces *all* current data with the data in the backup.
Anything entered after that backup was taken is lost. It cannot be merged.

## 1. Choose the backup

Backups are in the folder set as `ASRMS_BACKUP_PATH` in `backend\.env`, for
example `D:\ASRMS-Backups`:

| Folder | What it holds | Kept |
|---|---|---|
| `daily\` | every evening at 6:00 PM | 7 |
| `weekly\` | the Sunday backups | 4 |
| `monthly\` | the 1st-of-month backups | 12 |
| `pre-update\` | taken by `update-asrms.ps1` before each update | 10 |
| `pre-restore\` | taken by every restore, before it changes anything | 10 |

Files are named `asrms-YYYYMMDD-HHMM.zip`. Pick the newest backup from
**before** the problem started. To undo an update, use the newest file in
`pre-update\`.

## 2. Check it (changes nothing)

Open PowerShell in the `backend` folder:

```powershell
cd C:\ASRMS\backend
php artisan asrms:restore D:\ASRMS-Backups\daily\asrms-20261006-1800.zip
```

Without `--force` this only checks the file. It prints the date the backup was
made, the row counts now and in the backup, then says *"Restore refused ...
Nothing was changed."* If it says the backup is damaged, try an older one.

## 3. Restore

1. Tell the office the system will be unavailable for a few minutes.
2. **Stop the web server** (XAMPP Control Panel: Apache *Stop*), but keep
   **MySQL running**, because the restore loads the data into it. On SQLite, a
   running web server holds the database file open, and the restore stops
   safely with *"in use"*.
3. Run the same command with `--force`:

   ```powershell
   php artisan asrms:restore D:\ASRMS-Backups\daily\asrms-20261006-1800.zip --force
   ```

   In order, it:
   - checks the backup again;
   - takes a **safety backup** of the current data into `pre-restore\`;
   - turns maintenance mode on;
   - puts back the database and the uploaded documents;
   - runs the database migrations;
   - turns maintenance mode off.

4. Read the table it prints. The **After** column must equal **In backup**,
   and the last line must say *"Restore completed in N s."*
5. Start the web server again.

## 4. Check the system

Sign in as the registrar and open one student you know well. Check their
grades, their transcript and one uploaded document. Then sign in as the admin
and confirm the **Backups** card on the dashboard.

## If something goes wrong

- **"Restore refused" or "Restore FAILED ... while checking the backup":**
  nothing was changed. Use another backup file.
- **"The safety backup failed":** nothing was changed. The backup drive is
  probably full or disconnected. Fix that and run again.
- **"Restore FAILED" after "Maintenance mode on":** the message names the
  safety backup in `pre-restore\`. Restoring that file with `--force` puts back
  the data as it was just before.
- **The database file was deleted:** the restore still works. There is nothing
  to back up first, so it says so. The uploaded documents that were there are
  kept in `storage\app\private.replaced-<date>`. Delete that folder once the
  restore is checked.

Every restore, successful or not, is written to
`backend\storage\logs\backup.log` and to the system logs.
