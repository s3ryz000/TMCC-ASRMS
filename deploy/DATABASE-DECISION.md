# Production database: MySQL (MariaDB) or SQLite?

**Decision (recommended): use MySQL, in the form XAMPP installs it (MariaDB
10.4).** SQLite stays fully supported as the fallback, and the system was
tested on both on 8 October 2026 (issue #61).

## Why MySQL (MariaDB)

| | MySQL / MariaDB | SQLite |
|---|---|---|
| **What the manuscript and panel expect** | Planned in the manuscript (3.2) | Used during development and UAT |
| **Several registrar staff saving at the same time** | Handles it: rows are locked, not the whole database | One save at a time for the whole file; a second save waits or fails with "database is locked" |
| **Backups** | `mysqldump --single-transaction`, a consistent copy while people keep working | `VACUUM INTO`, also a consistent copy |
| **Restore** | `asrms:restore` loads the dump back (tested, see below) | `asrms:restore` swaps the file back (tested on 6 Oct, see `evidence/`) |
| **Moving parts on the server** | A database service that must be running, a database user and its password | None: one file in `backend\database\` |
| **Room to grow** | Many years of records and more users without changes | Fine for one office; heavier use means more waiting on saves |

The deciding points are **concurrent registrar users** during enrollment and
request season, and **matching the manuscript**. The extra moving parts are
covered by the installation checklist (`INSTALL.md`): XAMPP's MySQL runs as
a Windows service, ASRMS gets its own database user (never `root`), and the
nightly backup already knows how to dump and restore it.

## Test evidence (8 October 2026)

Laravel 12.54.1, PHP 8.2.12, on the development laptop.

| Check | SQLite 3.39 | MariaDB 10.4.32 (XAMPP) |
|---|---|---|
| `php artisan migrate:fresh --seed` (56 migrations + seeders) | passes | passes (after fix 1 below) |
| Full backend test suite (`php artisan test`) | **619 passed** | **614 passed, 5 skipped** |
| `asrms:backup`, then `asrms:restore --force` | passed (restore drill, 6 Oct) | passed: all 38 tables have the same row count as before; the damaged data and the deleted upload came back |
| Admin report export (`ReportController`) | passes (fixed in #84) | passes |

The backup/restore check on MariaDB took a backup, then deleted every grade
and record request, changed a subject title and deleted an uploaded file. The
restore brought all of it back. The only difference afterwards is one extra
`system_logs` row: the restore's own entry, as on SQLite.

**The 5 tests skipped on MariaDB** say "SQLite only" with the reason. They
replay one-time repairs of the existing SQLite data (subject code merging,
adding a key to grades). MySQL commits schema changes immediately, which ends
the test's wrapping transaction, so they can't run there. Those repairs ran
on the real data on SQLite and, on a new MySQL installation, find nothing to
repair (the migrations themselves run fine; see the first row). One more is
the SQLite-specific #84 regression test; the other report tests run the same
export on MariaDB.

### What had to change for MySQL (commit for #61)

1. **One index name was too long.** The unique index on
   `curriculum_prerequisites` got an automatic 70-character name; MySQL and
   MariaDB allow 64. It now has a short name. Nothing refers to it by name,
   and databases already created on SQLite keep the old name.
2. **Tests that assumed SQLite** were made to run on both:
   - the backup/restore tests now really use their temporary SQLite file
     (they also do on MySQL; the MySQL backup and restore were checked by
     hand, above);
   - the grades foreign-key test reads the schema through Laravel instead of
     SQLite's `PRAGMA`;
   - the student-number race test recognises MySQL's `` `username` `` quoting
     as well as SQLite's `"username"`.

No application code had to change: the report export was already made
database-neutral in #84, and every other query runs on both.

### Not tested

- **Oracle MySQL 8** (as opposed to XAMPP's MariaDB). Laravel supports both;
  if TMCC installs MySQL 8 instead of XAMPP's MariaDB, run the test suite
  against it once (step 9 of `INSTALL.md`) before go-live.
- **Moving the UAT data from SQLite into MySQL.** Go-live should start from an
  empty database with the real accounts (`INSTALL.md`), not from the UAT
  data, which holds test students.

## If TMCC chooses SQLite instead

Everything works as it does today. In `INSTALL.md`:

- set `DB_CONNECTION=sqlite` in `backend\.env` and leave out the `DB_HOST`,
  `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` lines; the
  database is the file `backend\database\database.sqlite` (create it empty
  before `php artisan migrate`);
- skip creating the MySQL database and user;
- backups and restores work the same way (`asrms:backup`, `asrms:restore`,
  the same folders and the same admin Backups card); `ASRMS_MYSQLDUMP_PATH`
  and `ASRMS_MYSQL_PATH` are not needed;
- for a restore, Apache must be stopped (a running web server holds the file
  open), as `RESTORE.md` says;
- expect occasional "database is locked" errors if several staff save at the
  very same moment. Keep the number of registrar accounts working at once
  small, or switch to MySQL later (a new installation plus re-entering or
  transferring the data, which needs a separate plan).

## If TMCC later moves from SQLite to MySQL

This is not a setting change. It needs a new MySQL database
(`php artisan migrate --force`), a one-time data transfer planned and tested
on a copy, a full backup before and after, and the restore drill on the new
database.
