# ASRMS — Automated Student Records Management System

A web system for the Registrar's Office of **Trece Martires City College (TMCC)**: student records, enrollment with curriculum and prerequisite rules, grades and academic standing, document requests with release appointments, transcripts, and administration. Capstone project of Group 6 (Ang, Badillo, Gison, Sendin), BS Information Technology, University of Asia and the Pacific.

| Part | Technology |
|---|---|
| Backend | Laravel 12, PHP 8.2+ (`backend/`) |
| Frontend | React 19, Create React App (`frontend/`) |
| Database | SQLite for development; MySQL planned for deployment |
| Auth | Laravel Sanctum tokens (one active session per account) |

Roles: **Student**, **Registrar Staff** (the only role that can change student records), **Admin** (users, settings, logs, reports; read-only on student records).

---

## 1. One-time setup (Windows)

### Install
- [Git](https://git-scm.com/download/win)
- [Node.js LTS](https://nodejs.org/)
- [XAMPP](https://www.apachefriends.org/) for PHP 8.2+. In `C:\xampp\php\php.ini`, remove the `;` in front of these lines: `extension=gd`, `extension=zip`, `extension=sqlite3`, `extension=pdo_sqlite`, `extension=intl`.
- [Composer](https://getcomposer.org/download/) (point it to `C:\xampp\php\php.exe` during install)

Ask the repository owner to add you as a collaborator, then accept the e-mail invite.

### Clone and install

In a terminal opened in your projects folder:

```
git clone https://github.com/s3ryz000/TMCC-ASRMS.git
cd TMCC-ASRMS\backend
```

In `backend\`:

```
composer install
copy .env.example .env
php artisan key:generate
type nul > database\database.sqlite
php artisan migrate --seed
```

In `frontend\`:

```
npm install
```

---

## 2. Run the system

Double-click **`start-asrms.bat`** in the repository folder. It opens two windows:

- backend at http://127.0.0.1:8000
- frontend at http://localhost:3000 (the browser opens when it finishes compiling)

Close both windows to stop. To start them by hand instead: `php artisan serve` in `backend\` and `npm start` in `frontend\`.

### Test accounts (local development only)

| Role | Username | Password |
|---|---|---|
| Admin | `admin` | `password123` |
| Registrar staff | `staff` | `password123` |
| Student | create one in Registrar → New Student; the username and password are shown after saving | |

Notes:
- Logging in signs that account out everywhere else. Use separate browsers or browser profiles for staff, admin and student at the same time.
- Your database (`backend\database\database.sqlite`) is your own local copy. Test data you create does not affect anyone else.

### Get the latest version

In the repository folder: `git pull`. Then in `backend\`: `composer install` and `php artisan migrate`. Then in `frontend\`: `npm install`.

---

## 3. Tests

In `backend\`:

```
php artisan test
```

All tests must pass before you push. In `frontend\`, `npm run build` must compile (the old frontend test setup is being repaired, see #39).

---

## 4. Working on tickets

The task board is the GitHub project **ASRMS Capstone 2**. `main` is the only permanent branch; the repository owner reviews and merges.

1. **Claim a ticket:** assign yourself and move it to **In Progress**. One person per ticket; an assigned ticket is taken.
2. **Start from the latest `main`** (in the repository folder):
   ```
   git checkout main
   git pull
   git checkout -b ticket-24-curriculum-entries
   ```
3. **Commit often**, only the files the ticket needs. Run the tests in section 3.
4. **Push and open a pull request:**
   ```
   git push -u origin ticket-24-curriculum-entries
   gh pr create --base main --fill
   ```
   Write `Closes #24` in the description and move the ticket to **In Review**.
5. After the merge, delete your branch and run `git pull` on `main` before the next ticket.

To avoid overlapping work:
- Tickets that touch the same screen go to the same person.
- Only one person adds database migrations at a time; say so in the group chat first.
- Keep pull requests small (one ticket) and merged within a day or two.
- Never commit `backend\.env`, `database.sqlite` or any file with passwords.

---

## 5. Notes for deployment on the campus network (LAN)

The production plan (server checklist, daily backups, off-machine copy, restore drill) is tracked in tickets #59–#67. When the system runs on a server instead of a laptop:

- Backend `.env`: set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` to the server address, `FRONTEND_URL` to the address users open (needed for CORS), and the MySQL `DB_*` settings.
- Frontend: create `frontend\.env` with `REACT_APP_API_URL=http://<server>:8000/api`, then build with `npm run build` and serve the `build` folder.
- Use a proper web server (Apache or Nginx with PHP-FPM) instead of `php artisan serve`, which handles one request at a time.
- The system needs no internet connection at runtime (QR codes are generated locally).
