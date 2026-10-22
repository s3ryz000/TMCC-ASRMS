# Updating the live site

The live site is `https://asrms-tmcc.duckdns.org`. Pushing to GitHub does **not** update it. Someone has to run one command on the server. That command is `asrms-update` (#108).

## Update the site (every time)

1. **Push to `main`** and wait until GitHub Actions shows a green tick for that commit (`gh run list -R s3ryz000/TMCC-ASRMS --limit 1` says `success`). Never update with a red or still-running build.
2. **Open the DigitalOcean web console**: DigitalOcean → the ASRMS Droplet → **Access** → **Launch Droplet Console**. Our network blocks SSH, so use the web console.
3. **Type this one line** (type it; don't paste long blocks, because the console garbles them):

   ```
   sudo asrms-update
   ```

4. Wait for **`Update complete: <old> → <new>`**. It usually takes a few minutes, mostly the frontend build. While it runs, the site answers "Service Unavailable" (503) and retries by itself.
5. **Check the site**: open `https://asrms-tmcc.duckdns.org`, sign in, and open the page you changed.

The command also stops early, changing nothing, in these cases:

- **"Already up to date"**: the server already runs the newest `main`.
- **"The server copy has local changes; nothing was updated."**: someone edited files on the server. Tell the Dev Lead; don't delete anything.

What it does, in order: shows the commits it will deploy → **backs up the database first** (`asrms:backup --label=pre-update`, into `/var/backups/asrms`) → maintenance mode on → `git merge --ff-only origin/main` → `composer install --no-dev` → `migrate --force` → caches config, routes and views → `npm ci` and `npm run build` → `chown www-data` → reloads PHP-FPM → maintenance mode off → checks that the home page answers 200 and the API answers 401 without a login. Everything is logged with times in `/var/log/asrms-update.log`.

It never touches `backend/.env` or `frontend/.env.production`.

## If it fails

It stops at the first error and **does not roll back by itself**. If the site was in maintenance mode, it turns it back on (`php artisan up`) so users aren't locked out. Then it prints the rollback steps with the real values filled in:

```
git -C /var/www/asrms reset --hard <OLD>
cd /var/www/asrms/backend && sudo -u www-data php artisan asrms:restore <zip> --force   # only if migrations ran
then re-run the backend and frontend build steps, chown, reload php8.2-fpm
```

What the lines mean:

- **`git … reset --hard <OLD>`** puts the code back to the version that was running before. `<OLD>` is that version's commit.
- **`asrms:restore <zip> --force`** puts the database back to the backup taken at the start of this update. Run it **only if the message says migrations ran**. It replaces all data with the backup, so anything entered after the update started is lost. It takes its own safety backup first.
- **The build steps** (printed in full) rebuild the old version's backend caches and frontend, fix ownership and reload PHP-FPM.

If the error happened **before the code changed** (for example the backup failed), the message says there is nothing to roll back. Fix the cause and run `sudo asrms-update` again.

When in doubt, **stop and send the Dev Lead a screenshot** of the last screen and the log (`sudo tail -n 80 /var/log/asrms-update.log`). The site is up in either case.

## First-time install (once per server)

The command has to be installed once, and again whenever `deploy/asrms-update.sh` changes:

```
cd /var/www/asrms && sudo git pull && sudo bash deploy/install-asrms-update.sh
```

This copies the script to `/usr/local/bin/asrms-update`.

## When not to update

- **During registrar office hours.** The site is unavailable for a few minutes, and a database migration can't be undone without losing that day's work. Update early in the morning, at lunch, or after office hours.
- **During UAT or survey sessions** with TMCC, or right before one.
- **When GitHub Actions is red or still running** for the commit.
- **When nobody can check the site afterwards.**

After an update that adds a permission or setting, do the one-time setup the ticket names. For example, after #107: Admin → User Management → Edit the registrar head → tick **Can approve grades**.
