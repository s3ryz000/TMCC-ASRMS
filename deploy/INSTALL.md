# Installing TMCC ASRMS on the TMCC server

This checklist installs the Automated Student Records Management System on
one Windows PC on the registrar's LAN. Follow the steps in order. Each step
says what **success** looks like. If a step does not end that way, stop and
fix it before going on.

Allow about half a day the first time. You need administrator rights on the
PC, the repository address, and someone from TMCC's IT/admin office for
steps 1, 4 and 9.

Commands are typed in **PowerShell**. Where a step says *(as administrator)*,
open PowerShell with right-click > *Run as administrator*. Text in
`<angle brackets>` is a placeholder: replace it, including the brackets.

The examples use `C:\ASRMS` for the program and `D:\ASRMS-Backups` for the
backups. If you use other folders, change them everywhere, including in
`apache-asrms.conf.example`.

---

## 1. Choose the server and where it stands

TMCC chooses one of these:

| Option | What it is | When |
|---|---|---|
| **A. Dedicated PC (recommended)** | A PC used only for ASRMS: 4-core CPU, 16 GB RAM, SSD for Windows and the program, a **second drive** (internal, or external USB) for backups, **wired** LAN, and a **UPS** | The normal choice |
| **B. Separate Windows account** | An existing office PC with a separate Windows account used only for ASRMS, and a second drive for backups | Only if a dedicated PC is not possible yet |

Also decide and write down:

- **Where it stands:** a room that is locked when the office is closed, not
  on a public counter. Plug it into the UPS.
- **Who has keys** to that room, and who knows the Windows administrator
  password (TMCC's IT/admin office, not the student developers).
- **Its fixed LAN address** (ask IT to reserve one, e.g. `192.168.1.20`) and,
  if IT can, a name such as `asrms.tmcc.local`. Staff will type this in the
  browser.

**Success:** the PC is in place, on the UPS and the wired LAN, with Windows
updated, BitLocker set up (see `OFFSITE-AND-BITLOCKER.md`), and the decisions
above written in the sign-off table at the end.

## 2. Install the software

Download and install, accepting the defaults unless noted:

1. **XAMPP for Windows with PHP 8.2** (apachefriends.org). It includes Apache
   (the web server), PHP and MySQL (MariaDB). Install to `C:\xampp`.
2. **Composer** (getcomposer.org, `Composer-Setup.exe`). When asked for PHP,
   choose `C:\xampp\php\php.exe`.
3. **Node.js LTS** (nodejs.org, the "LTS" version).
4. **Git for Windows** (git-scm.com).

PHP needs these extensions. XAMPP has them all switched on except possibly
`intl`, `gd` and `zip`: open `C:\xampp\php\php.ini` in Notepad,
search for each `;extension=` line below and remove the `;` at the start.

`curl`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo_mysql`,
`pdo_sqlite`, `sqlite3`, `zip`

In the same file, set `date.timezone=Asia/Manila`.

Then, in a **new** PowerShell window:

```powershell
C:\xampp\php\php.exe -v
C:\xampp\php\php.exe -m
composer --version
node -v
npm -v
git --version
```

**Success:** PHP says `PHP 8.2.x`; the `-m` list contains every extension
above; the other four print a version number.

> **Why Apache and not `php artisan serve`?** `php artisan serve` is a
> development tool that answers one request at a time: with several
> registrar staff working, everyone waits for everyone else. Apache answers
> many requests at once and runs as a Windows service.

## 3. Get the program

```powershell
git clone https://github.com/s3ryz000/TMCC-ASRMS.git C:\ASRMS
cd C:\ASRMS
git log --oneline -1
```

The repository owner gives the server read access to the repository (or
copies it onto the PC).

**Success:** `C:\ASRMS` contains the folders `backend`, `frontend` and
`deploy`, and `git log` shows the latest change.

## 4. Create the database (MySQL) *(TMCC IT)*

The recommended database is MySQL; the reasons are in
`DATABASE-DECISION.md`. (For SQLite instead, follow that file's section
*"If TMCC chooses SQLite instead"* and skip this step.)

1. Open the **XAMPP Control Panel** *(as administrator)*. Next to **MySQL**
   and **Apache**, tick the **Service** boxes so both start with Windows.
   Click **Start** for MySQL.
2. XAMPP's MySQL `root` account has **no password** after installation.
   Give it one (keep it with the other TMCC passwords, not in this
   repository):

   ```powershell
   C:\xampp\mysql\bin\mysqladmin.exe -u root password "<choose a strong root password>"
   ```

   phpMyAdmin then needs to ask for it: in `C:\xampp\phpMyAdmin\config.inc.php`
   change `'auth_type'` from `'config'` to `'cookie'`.
3. Create the ASRMS database and its own user:

   ```powershell
   C:\xampp\mysql\bin\mysql.exe -u root -p
   ```

   Type the root password, then:

   ```sql
   CREATE DATABASE asrms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'asrms'@'localhost' IDENTIFIED BY '<choose a strong password>';
   GRANT ALL PRIVILEGES ON asrms.* TO 'asrms'@'localhost';
   FLUSH PRIVILEGES;
   EXIT;
   ```

   Only if TMCC installed **Oracle MySQL 8** instead of XAMPP's MySQL, and
   the backup in step 10 fails with *"PROCESS privilege"*, also run
   `GRANT PROCESS ON *.* TO 'asrms'@'localhost';`.

**Success:** `C:\xampp\mysql\bin\mysql.exe -u asrms -p asrms -e "SELECT 1;"`
asks for the `asrms` password and prints `1`.

## 5. Configure the program

1. Copy the production settings file:

   ```powershell
   cd C:\ASRMS\backend
   Copy-Item .env.production.example .env
   notepad .env
   ```

2. In Notepad, replace every `<...>`:
   - `APP_URL` and `FRONTEND_URL`: the address staff will type, e.g.
     `http://asrms.tmcc.local` or `http://192.168.1.20` (both the same);
   - `DB_PASSWORD`: the `asrms` password from step 4;
   - `ASRMS_BACKUP_PATH`: a folder on the **second drive**, e.g.
     `D:\ASRMS-Backups` (create it in File Explorer).

   Leave `APP_ENV=production` and `APP_DEBUG=false` as they are. Save and
   close.
3. Tell the frontend where the API is (this file stays on the server and is
   used by every later build):

   ```powershell
   Set-Content -Path C:\ASRMS\frontend\.env.production.local -Value 'REACT_APP_API_URL=/api' -Encoding ascii
   ```

**Success:** `backend\.env` exists and contains no `<`; the backup folder
exists on the second drive; `frontend\.env.production.local` contains
`REACT_APP_API_URL=/api`.

## 6. Install the backend

```powershell
cd C:\ASRMS\backend
composer install --no-dev --optimize-autoloader --no-interaction
C:\xampp\php\php.exe artisan key:generate --force
C:\xampp\php\php.exe artisan migrate --force
C:\xampp\php\php.exe artisan db:seed --force
C:\xampp\php\php.exe artisan optimize
```

`db:seed` creates only what a new installation needs: the roles, **two
starter accounts** (`admin` and `staff`, both with the password
`password123`, which you change in step 9), the BSTM, BSHM and BSE programs
with their curricula, and the default settings. It creates **no students**.

> Never run `php artisan migrate:fresh`, `migrate:reset` or
> `db:seed --class=StudentDataSeeder` on the server: the first two erase
> every record, the last adds demo students.

**Success:** `migrate` ends with every migration `DONE`; `db:seed` prints
*"Accounts created successfully"*; `optimize` ends with every line `DONE`.

> After any later change to `backend\.env`, run
> `C:\xampp\php\php.exe artisan optimize` again, or the change is ignored.

## 7. Build the frontend and set up Apache

1. Build the browser app:

   ```powershell
   cd C:\ASRMS\frontend
   npm ci
   npm run build
   ```

   **Success:** *"The build folder is ready to be deployed."* (Warnings
   above it are fine.)

2. Apache configuration *(as administrator)*:
   1. Copy `C:\ASRMS\deploy\apache-asrms.conf.example` to
      `C:\xampp\apache\conf\extra\asrms.conf`. If ASRMS is not in
      `C:\ASRMS`, change the four `C:/ASRMS` paths in it.
   2. Open `C:\xampp\apache\conf\httpd.conf` in Notepad:
      - remove the `#` at the start of
        `#LoadModule proxy_http_module modules/mod_proxy_http.so`;
      - add this line at the very end: `Include conf/extra/asrms.conf`.
   3. Check the configuration:

      ```powershell
      C:\xampp\apache\bin\httpd.exe -t
      ```

      **Success:** `Syntax OK`.
   4. In the XAMPP Control Panel, **Stop** and **Start** Apache.
3. Allow other PCs to reach the server *(as administrator)*:

   ```powershell
   New-NetFirewallRule -DisplayName "TMCC ASRMS (HTTP)" -Direction Inbound -Protocol TCP -LocalPort 80 -Action Allow -Profile Domain,Private
   ```

   Port 8000 (Laravel) and 3306 (MySQL) stay closed: only the server itself
   uses them.

**Success:**

- On the server, `http://localhost` shows the ASRMS sign-in page.
- On another office PC, `http://<server name or IP>` shows the same page.
- `http://<server name or IP>/api/settings/current` answers
  `{"message":"Unauthenticated."}`, which means the API is up and refuses
  guests.
- `http://<server name or IP>/phpmyadmin` from another PC is **refused**
  (403). phpMyAdmin must only work on the server itself.

## 8. Run the automatic checks once

```powershell
cd C:\ASRMS\backend
C:\xampp\php\php.exe artisan about --only=environment,drivers
```

**Success:** *Environment* is `production`, *Debug Mode* is `OFF`,
*Database* is `mysql` (or `sqlite` if chosen).

## 9. First sign-in: replace the starter passwords *(TMCC IT and the registrar)*

Do this **before** anyone else uses the system. The starter password
`password123` is written in this repository, so anyone could know it.

1. Open the system in the browser and sign in as `admin` / `password123`.
2. Click **Change Password** and set a strong password for `admin`. Keep it
   with TMCC IT. *Success:* you can sign out and back in with the new
   password.
3. Open **User Management**:
   1. **Create the real registrar accounts**, one per person, role
      *Staff*, each with their own strong password. Never share
      one account between people: the system log records who did what.
   2. Open the starter account **`staff`** and **deactivate** it (Status:
      *Inactive*), or delete it. It must not stay active with `password123`.
4. Sign out. Sign in as one of the new registrar accounts and change its
   password via **Change Password** (so only that person knows it).

**Success:** `admin` and `staff` no longer accept `password123`; each
registrar signs in with their own account.

## 10. The safety net: backups

1. Register the nightly backup (6:00 PM every day) *(as administrator)*:

   ```powershell
   powershell -ExecutionPolicy Bypass -File C:\ASRMS\deploy\windows\register-backup-task.ps1 -BackendPath "C:\ASRMS\backend" -PhpPath "C:\xampp\php\php.exe"
   ```

2. Run one backup now:

   ```powershell
   cd C:\ASRMS\backend
   C:\xampp\php\php.exe artisan asrms:backup
   ```

   **Success:** *"Backup completed: D:\ASRMS-Backups\daily\asrms-....zip"*
   and a table of row counts. Write the file name down: step 12 uses it.
3. Also run the scheduled task once: Task Scheduler > *TMCC ASRMS Daily
   Backup* > **Run**. *Success:* a second file in `D:\ASRMS-Backups\daily`.
4. Sign in as `admin`. *Success:* the **Backups** card on the dashboard is
   green, *"Backups are working"*.

The admin then follows `ADMIN-WEEKLY-CHECK.md` every Monday, and the
registrar-in-charge makes the weekly off-machine copy in
`OFFSITE-AND-BITLOCKER.md` every Friday.

## 11. Acceptance checks

Do these with **test data** (a made-up student). Step 12 removes it again.

1. **Registrar:** sign in, **New Student**, create a test student (e.g.
   *Test Student*). *Success:* the student is saved and a one-time password
   is shown. Write it down.
2. **Registrar:** add one enrollment and a grade for the test student, then
   download the student's **transcript**. *Success:* a PDF opens.
3. **Student:** in another browser (or a private window), sign in as the test
   student with the one-time password and request a **Transcript of
   Records**. *Success:* the request shows as *Pending*.
4. **Registrar:** approve the request with an appointment time. *Success:*
   the student's portal shows *Approved* with the date, and the bell shows a
   notification.
5. **Approval slip:** download the slip and scan its **QR code** with a phone
   connected to the office Wi-Fi/LAN. *Success:* the phone opens the
   appointment page on the server.
6. **Admin:** open **System Logs**. *Success:* the sign-ins and the approval
   are listed, newest first, with names.
7. **No internet:** unplug the server's connection to the internet (keep the
   LAN), or ask IT to block it, and repeat checks 1 to 4 quickly. *Success:*
   everything works the same; the system needs only the LAN.
8. **Roles:** while signed in as the registrar, type `/admin` after the
   address. *Success:* you are sent back to the registrar pages.

## 12. Restore drill

This proves a backup can really be put back, and it also removes the test
data from step 11. Follow `RESTORE.md`, using the backup **from step 10**
(taken before the test data existed):

```powershell
cd C:\ASRMS\backend
C:\xampp\php\php.exe artisan asrms:restore D:\ASRMS-Backups\daily\<file from step 10>.zip
C:\xampp\php\php.exe artisan asrms:restore D:\ASRMS-Backups\daily\<file from step 10>.zip --force
```

(Stop Apache before the `--force` run and start it again afterwards, as
`RESTORE.md` says.)

**Success:** the last line says *"Restore completed in N s."*, the **After**
column equals **In backup**, the test student from step 11 is gone, and the
real registrar accounts from step 9 still sign in. Record the date in the
sign-off table below.

## 13. Updating later

When the developers release a new version, outside office hours:

```powershell
cd C:\ASRMS
powershell -ExecutionPolicy Bypass -File deploy\windows\update-asrms.ps1 -PhpPath "C:\xampp\php\php.exe" -ApiUrl "http://localhost:8000/api"
```

It takes a backup first (into `pre-update\`), then updates the code, the
database and the browser app, and checks the API is answering. It stops at
the first problem. **Success:** the last line says *"Update finished"*.
If anything goes wrong after the backup, `RESTORE.md` explains how to go
back using the newest file in `pre-update\`.

---

## Sign-off

| Step | Done on | Done by | Checked by | Notes |
|---|---|---|---|---|
| 1. Server, location, keys decided | | | | |
| 2–8. Installed and checks passed | | | | |
| 9. Starter passwords replaced | | | | |
| 10. Backups running, card green | | | | |
| 11. Acceptance checks passed | | | | |
| 12. Restore drill passed | | | | |
| Installed by someone who did not write this checklist | | | | |
