# Weekly off-machine copy and BitLocker

The nightly backup (`INSTALL.md`, step 10) protects against mistakes and a
broken database, but it sits in the same PC as the system. A fire, a theft
or a dead PC would take both. This procedure keeps a recent copy **away from
the server**, and BitLocker makes sure a stolen PC or drive does not expose
students' personal data (Data Privacy Act).

| Who | Does what |
|---|---|
| **Registrar-in-charge** (named in the sign-off table) | The weekly copy, every Friday |
| **TMCC IT/admin office** | Sets up BitLocker once, keeps the recovery keys, keeps the cabinet key |
| **System administrator** (the ASRMS `admin`) | Checks every Monday that last Friday's copy was made |

The student developers set none of this up and never hold the recovery keys
or drive passwords.

---

## Part 1: what you need

- **Two external USB drives** of the same size (each at least 10 times the
  size of one backup; any 500 GB or larger drive is plenty). Label them
  **ASRMS Drive A** and **ASRMS Drive B**.
- A **locked cabinet in a different room** from the server. If the server
  room burns or is broken into, the drive in the cabinet must survive. Only
  the registrar-in-charge and TMCC IT have its key.
- BitLocker set up on the server and both drives (Part 3), **before** the
  first copy.

The two drives take turns: **Drive A on odd-numbered weeks, Drive B on
even-numbered weeks**. So one drive is always in the cabinet, even while the
other is plugged into the server, and each keeps the last 8 weekly copies
(16 weeks between them).

## Part 2: the weekly copy (registrar-in-charge, every Friday)

Do this **after 6:00 PM** on Friday, once the evening backup has run. (If
Friday is a holiday, do it on the next working day after 6:00 PM.)

1. Take this week's drive (A or B) from the cabinet. Lock the cabinet again.
2. Plug it into the server. Windows asks for the **drive password**: type
   it and click **Unlock**. Note its drive letter in File Explorer (e.g.
   `E:`).
3. Open PowerShell and run (change `E:` if the letter is different):

   ```powershell
   powershell -ExecutionPolicy Bypass -File C:\ASRMS\deploy\windows\copy-weekly-backup.ps1 -BackupPath "D:\ASRMS-Backups" -Destination "E:\ASRMS-Offsite"
   ```

   It copies that evening's backup to the drive, checks the copy is
   identical and complete (the same checks a restore does), removes copies
   older than the newest 8 on that drive, and writes one line to
   `C:\ASRMS\backend\storage\logs\backup.log`.
4. **Success:** the last line says *"Off-machine copy done: asrms-....zip
   ..., SHA-256 and manifest verified, N copies on the drive"*.
5. In File Explorer, right-click the drive > **Eject**. Unplug it and put it
   back in the cabinet. Lock the cabinet.
6. Fill in one row of the record below.

| Date | Drive (A/B) | Backup file copied | Result (OK / problem) | Initials |
|---|---|---|---|---|
| | | | | |

**On Monday**, the system administrator checks that last Friday has a row
marked OK (and, on the server, a matching `OFFSITE OK` line at the end of
`backup.log`).

### If the copy says FAILED

Nothing on the drive is half-written: a failed copy is never kept. Read the
reason:

| Message | What to do |
|---|---|
| *"The drive E:\ is not connected"* | Plug in and unlock the drive; check the letter in File Explorer; run again. |
| *"on the same drive as the backups"* or *"the Windows drive"* | `-Destination` points at the wrong drive. Use the external drive's letter. |
| *"BitLocker on E:\ is OFF"* or *"could not be confirmed"* | **Stop.** The drive is not encrypted (or locked). Do not copy personal data to it. Tell TMCC IT (Part 3C). |
| *"No backup (asrms-*.zip) in ..."* | The evening backup did not run. Tell the system administrator; the Backups card on the admin dashboard is probably red. |
| *"does not match its checksum"* | The backup itself is damaged. Tell the system administrator today; run the copy again after the next good backup. |
| *"The copy is not identical"* | Run again. If it repeats, the drive may be failing: use the other drive and tell TMCC IT. |

## Part 3: BitLocker (TMCC IT, once)

BitLocker is part of **Windows Pro, Enterprise and Education**. Windows Home
does not have it. Check under *Settings > System > About > Edition*, and
upgrade the server if it says Home.

For every drive below, Windows offers to **back up the recovery key**. The
recovery key is the only way into the drive if the password or the PC's
security chip fails. Handle it like this:

- **Print it** (two copies). Keep one in TMCC IT's locked safe and one in a
  sealed, signed envelope with the school administrator (or as TMCC's
  policy says). You may also save it to TMCC IT's own account or secure
  storage.
- **Never** save it on the server, on the drive it unlocks, on the other
  backup drive, in this repository, or with the student developers.
- Write only *where* each key is kept in the key register below (not the
  key itself).

### 3A. The server's system drive (C:)

1. Sign in to the server with an administrator account.
2. Open **Control Panel > System and Security > BitLocker Drive
   Encryption**.
3. Next to **C:**, click **Turn on BitLocker**.
4. Back up the recovery key as described above (**Print the recovery key**,
   and/or **Save to a file** on a USB stick that TMCC IT then keeps).
5. Choose **Encrypt entire drive** and **New encryption mode**.
6. Tick **Run BitLocker system check**, click **Start encrypting**, and
   restart when asked.

**Success:** after the restart, the BitLocker page shows **C: BitLocker on**
(encryption continues in the background; the PC can be used meanwhile).

### 3B. The internal backup drive (D:)

1. On the same BitLocker page, under **Fixed data drives**, click **Turn on
   BitLocker** next to **D:**.
2. Choose **Use a password to unlock the drive** and set a strong password
   (kept by TMCC IT).
3. Back up the recovery key as above.
4. Choose **Encrypt entire drive** and **New encryption mode**, then **Start
   encrypting**.
5. When it is done, click **Turn on auto-unlock** next to D:. **This is
   required:** the 6:00 PM backup runs with nobody signed in and must be
   able to write to D: after every restart.

**Success:** D: shows **BitLocker on** and **auto-unlock on**. The next
evening's backup still appears in `D:\ASRMS-Backups\daily` (check the
Backups card the next morning).

### 3C. Each external drive (BitLocker To Go), A and B

1. Plug the drive into the server. On the BitLocker page, under **Removable
   data drives - BitLocker To Go**, click **Turn on BitLocker** next to it.
2. Choose **Use a password to unlock the drive**. Set a strong password and
   give it only to the registrar-in-charge and TMCC IT. Use the same
   password for both drives if that is simpler for the office.
3. Back up the recovery key as above (each drive has its own key).
4. Choose **Encrypt entire drive** and **Compatible mode** (so another
   Windows PC can unlock it in an emergency, with the password).
5. **Do not** turn on auto-unlock for the external drives. A drive found or
   stolen must ask for the password everywhere.

**Success:** the drive shows **BitLocker on**, and its icon in File Explorer
has a padlock.

### Key register (where keys are kept, never the keys)

| Drive | Recovery key ID (first 8 characters) | Printed copy 1 kept at | Printed copy 2 kept at | Date | By |
|---|---|---|---|---|---|
| Server C: | | | | | |
| Server D: (backups) | | | | | |
| ASRMS Drive A | | | | | |
| ASRMS Drive B | | | | | |

The key ID appears on the printed recovery key and on the screen Windows
shows when it asks for the recovery key, so the right key can be found.

## Part 4: check that it really is protected (TMCC IT, once)

Do this after Part 3 and the first weekly copy, on **another PC** (not the
server), with someone watching:

1. Plug **ASRMS Drive A** into the other PC.
   **Success:** Windows says the drive is **BitLocker-protected** and asks
   for a password. File Explorer shows it with a closed padlock, and opening
   it shows nothing until it is unlocked.
2. Click **Cancel** (do not type the password). Open PowerShell on that PC
   and try to list the drive (use its letter):

   ```powershell
   Get-ChildItem E:\
   ```

   **Success:** an error (the drive is locked or cannot be accessed). No
   file names appear.
3. Now unlock it with the password and open `ASRMS-Offsite`.
   **Success:** the backup files (`asrms-....zip`) are there. This proves
   that the password is the only thing standing between a finder and the
   data. Eject the drive.
4. Repeat 1 to 3 with **ASRMS Drive B**.
5. On the **server**, in PowerShell *(as administrator)*:

   ```powershell
   manage-bde -status
   ```

   **Success:** **C:**, **D:** and any connected ASRMS drive show
   *Conversion Status: Fully Encrypted* and *Protection Status: Protection
   On*.

Record the result in the sign-off table.

## Sign-off (first setup)

| Item | Date | Done by | Witnessed by |
|---|---|---|---|
| Registrar-in-charge named: ____________________ | | | |
| Cabinet in a different room chosen; key holders: ____________________ | | | |
| BitLocker on server C: (3A) | | | |
| BitLocker on server D: with auto-unlock (3B); next evening's backup OK | | | |
| BitLocker To Go on Drive A and Drive B (3C) | | | |
| Recovery keys printed and stored; key register filled in | | | |
| First weekly copy made (Part 2) | | | |
| Drives unreadable without the password on another PC (Part 4) | | | |
| `manage-bde -status` shows all drives protected (Part 4) | | | |
