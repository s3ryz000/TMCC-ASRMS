<#
.SYNOPSIS
  Updates TMCC ASRMS on the server, always taking a backup first (#62).

.DESCRIPTION
  Run from the repository root on the TMCC server, outside office hours:

    powershell -ExecutionPolicy Bypass -File deploy\windows\update-asrms.ps1 `
      -PhpPath "C:\xampp\php\php.exe" -ApiUrl "http://localhost:8000/api"

  Steps, each stopping the update on failure:
    1. Refuse if the working tree has uncommitted changes.
    2. php artisan asrms:backup --label=pre-update   (kept in <ASRMS_BACKUP_PATH>\pre-update, 10 copies)
    3. git pull
    4. composer install --no-dev --optimize-autoloader
    5. npm ci, then npm run build                   (frontend)
    6. php artisan migrate --force
    7. php artisan optimize
    8. Health check: GET <ApiUrl>/settings/current must answer 401 JSON (the API is up and refuses guests).

  If anything after step 2 fails, the pre-update backup is the way back:
  see deploy\RESTORE.md.
#>
param(
    [string] $RepoPath = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path,
    [string] $PhpPath = 'C:\xampp\php\php.exe',
    [string] $ComposerPath = 'composer',
    [string] $NpmPath = 'npm',
    [string] $ApiUrl = 'http://localhost:8000/api'
)

$ErrorActionPreference = 'Stop'
$backend = Join-Path $RepoPath 'backend'
$frontend = Join-Path $RepoPath 'frontend'

function Step([string] $name, [scriptblock] $run) {
    Write-Host "==> $name"
    & $run
    if ($LASTEXITCODE -ne 0) { throw "Update stopped: '$name' failed (exit code $LASTEXITCODE)." }
}

Push-Location $RepoPath
try {
    # 1. Only update a clean working tree.
    $dirty = git status --porcelain
    if ($LASTEXITCODE -ne 0) { throw 'git status failed; is this the ASRMS repository?' }
    if ($dirty) {
        Write-Host $dirty
        throw 'Update stopped: the working tree has uncommitted changes. Commit or discard them first.'
    }

    # 2. Backup first, always.
    Push-Location $backend
    try { Step 'Backup before update' { & $PhpPath artisan asrms:backup --label=pre-update } } finally { Pop-Location }

    # 3-7.
    Step 'git pull' { git pull --ff-only }
    Push-Location $backend
    try {
        Step 'composer install' { & $ComposerPath install --no-dev --optimize-autoloader --no-interaction }
    } finally { Pop-Location }
    Push-Location $frontend
    try {
        Step 'npm ci' { & $NpmPath ci }
        Step 'npm run build' { & $NpmPath run build }
    } finally { Pop-Location }
    Push-Location $backend
    try {
        Step 'php artisan migrate' { & $PhpPath artisan migrate --force }
        Step 'php artisan optimize' { & $PhpPath artisan optimize }
    } finally { Pop-Location }

    # 8. Health check: the API answers 401 JSON to a guest.
    Write-Host "==> Health check: $ApiUrl/settings/current"
    try {
        Invoke-WebRequest -Uri "$ApiUrl/settings/current" -Headers @{ Accept = 'application/json' } -UseBasicParsing | Out-Null
        throw 'Health check failed: the API answered without asking for a login.'
    } catch [System.Net.WebException] {
        if ($null -eq $_.Exception.Response) { throw "Health check failed: no answer from $ApiUrl ($($_.Exception.Message))." }
        $status = [int] $_.Exception.Response.StatusCode
        if ($status -ne 401) { throw "Health check failed: expected 401, got $status." }
    }
    Write-Host 'Update finished: backup taken, code and database updated, API answering.'
}
finally {
    Pop-Location
}
