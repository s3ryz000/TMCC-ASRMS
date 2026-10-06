<#
.SYNOPSIS
  Registers the TMCC ASRMS nightly backup (#62) in Windows Task Scheduler.

.DESCRIPTION
  Creates (or replaces) the task "TMCC ASRMS Daily Backup", which runs
  `php artisan asrms:backup` from the backend folder every day at 6:00 PM,
  whether or not anyone is signed in. Run it once on the TMCC server, in an
  elevated PowerShell (Run as administrator):

    powershell -ExecutionPolicy Bypass -File deploy\windows\register-backup-task.ps1 `
      -BackendPath "C:\ASRMS\backend" -PhpPath "C:\xampp\php\php.exe"

  Before registering it, set ASRMS_BACKUP_PATH in backend\.env to a folder on
  the server's second drive (for example D:\ASRMS-Backups). Then run the task
  once by hand (Task Scheduler > TMCC ASRMS Daily Backup > Run) and check that
  a file appears in <ASRMS_BACKUP_PATH>\daily and that the admin's Backups card
  (#65) is green.

  The task runs as SYSTEM so it works with nobody signed in. Each run appends
  one line to backend\storage\logs\backup.log and writes a system log entry.
#>
param(
    [Parameter(Mandatory = $true)][string] $BackendPath,
    [string] $PhpPath = 'C:\xampp\php\php.exe',
    [string] $At = '18:00',
    [string] $TaskName = 'TMCC ASRMS Daily Backup'
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path (Join-Path $BackendPath 'artisan'))) {
    throw "No Laravel backend at $BackendPath (artisan not found)."
}
if (-not (Test-Path $PhpPath)) {
    throw "php.exe not found at $PhpPath."
}

$action = New-ScheduledTaskAction -Execute $PhpPath -Argument 'artisan asrms:backup' -WorkingDirectory $BackendPath
$trigger = New-ScheduledTaskTrigger -Daily -At $At
$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -DontStopIfGoingOnBatteries -AllowStartIfOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Hours 2) `
    -RestartCount 2 -RestartInterval (New-TimeSpan -Minutes 15)
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal `
    -Description 'TMCC ASRMS: back up the database and uploaded files (7 daily / 4 weekly / 12 monthly). See deploy\RESTORE.md.' `
    -Force | Out-Null

Write-Host "Registered '$TaskName': daily at $At, running '$PhpPath artisan asrms:backup' in $BackendPath."
Write-Host "Run it once now from Task Scheduler and confirm a new file in <ASRMS_BACKUP_PATH>\daily."
