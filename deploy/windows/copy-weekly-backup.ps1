<#
.SYNOPSIS
  Copies the newest TMCC ASRMS backup to the encrypted external drive and
  verifies it (#63, weekly off-machine copy).

.DESCRIPTION
  Run every Friday after the 6:00 PM backup, with the external drive plugged
  in and unlocked (see deploy\OFFSITE-AND-BITLOCKER.md):

    powershell -ExecutionPolicy Bypass -File C:\ASRMS\deploy\windows\copy-weekly-backup.ps1 `
      -BackupPath "D:\ASRMS-Backups" -Destination "E:\ASRMS-Offsite"

  Steps, each stopping the copy on failure:
    1. Find the newest backup set in <BackupPath>\<Folder> (by default
       daily\, i.e. that evening's backup; weekly\ only holds Sunday's).
    2. Refuse a destination on the same drive as the backups, and one whose
       drive does not report BitLocker as on.
    3. Check the backup against its manifest.json (database and uploaded
       files checksums), exactly as "php artisan asrms:restore" does.
    4. Copy it, compare the SHA-256 of the copy with the original, and check
       the copy against its manifest too.
    5. Keep the newest <Keep> copies on the drive and delete older ones
       (only asrms-*.zip files in the destination folder).
    6. Append one line to backup.log: OFFSITE OK or OFFSITE FAILED.

  Nothing in the backup folder is changed. Exit code 0 means the copy is on
  the drive and verified.
#>
param(
    [Parameter(Mandatory = $true)][string] $BackupPath,
    [Parameter(Mandatory = $true)][string] $Destination,
    [ValidateSet('daily', 'weekly')][string] $Folder = 'daily',
    [ValidateRange(1, 100)][int] $Keep = 8,
    [string] $LogPath = (Join-Path $PSScriptRoot '..\..\backend\storage\logs\backup.log'),
    [switch] $SkipEncryptionCheck
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

function Write-BackupLog([string] $status, [string] $text) {
    $line = '[{0}] OFFSITE {1} {2}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $status, $text
    try {
        Add-Content -Path $LogPath -Value $line -Encoding UTF8
    } catch {
        Write-Warning "Could not write to $LogPath ($($_.Exception.Message)). Record this line by hand: $line"
    }
}

function Get-Sha256Hex([System.IO.Stream] $stream) {
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        return (($sha.ComputeHash($stream) | ForEach-Object { $_.ToString('x2') }) -join '')
    } finally {
        $sha.Dispose()
    }
}

# The same checks as BackupService::verify() in the backend.
function Test-BackupSet([string] $zipPath) {
    $zip = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
    try {
        $manifestEntry = $zip.GetEntry('manifest.json')
        if ($null -eq $manifestEntry) { throw 'The backup has no manifest.json.' }
        $reader = New-Object System.IO.StreamReader($manifestEntry.Open())
        try { $manifest = $reader.ReadToEnd() | ConvertFrom-Json } finally { $reader.Dispose() }
        if ($manifest.app -ne 'TMCC ASRMS' -or -not $manifest.database.file) { throw 'The backup has no valid manifest.json.' }

        $dbEntry = $zip.GetEntry('database/' + $manifest.database.file)
        if ($null -eq $dbEntry) { throw 'The database is missing from the backup.' }
        $stream = $dbEntry.Open()
        try { $dbHash = Get-Sha256Hex $stream } finally { $stream.Dispose() }
        if ($dbHash -ne $manifest.database.sha256) { throw 'The database in the backup does not match its checksum.' }

        $lines = New-Object System.Collections.Generic.List[string]
        foreach ($entry in $zip.Entries) {
            $name = $entry.FullName
            if ($name -eq '' -or $name.Contains('\') -or $name.Contains(':') -or $name.StartsWith('/') -or ($name.Split('/') -contains '..')) {
                throw 'The backup contains an unsafe file path.'
            }
            if ($name.StartsWith('files/') -and -not $name.EndsWith('/')) {
                $stream = $entry.Open()
                try { $hash = Get-Sha256Hex $stream } finally { $stream.Dispose() }
                $lines.Add($name.Substring(6) + ':' + $hash)
            }
        }
        $sorted = $lines.ToArray()
        [Array]::Sort($sorted, [StringComparer]::Ordinal)
        $bytes = [System.Text.Encoding]::UTF8.GetBytes(($sorted -join "`n"))
        $memory = New-Object System.IO.MemoryStream(, $bytes)
        try { $filesHash = Get-Sha256Hex $memory } finally { $memory.Dispose() }
        if ($filesHash -ne $manifest.files.sha256 -or $sorted.Count -ne [int] $manifest.files.count) {
            throw 'The uploaded files in the backup do not match their checksum.'
        }
        return $manifest
    } finally {
        $zip.Dispose()
    }
}

function Get-BitLockerState([string] $driveRoot) {
    # Read through Windows Explorer, so no administrator rights are needed.
    # 1 = on, 3 = encrypting; 2 = off. Other values: not confirmed.
    try {
        $shell = New-Object -ComObject Shell.Application
        $value = $shell.NameSpace($driveRoot).Self.ExtendedProperty('System.Volume.BitLockerProtection')
        if ($null -eq $value) { return $null }
        return [int] $value
    } catch {
        return $null
    }
}

$name = $null
try {
    # 1. The newest backup set.
    $source = Join-Path $BackupPath $Folder
    if (-not (Test-Path $source -PathType Container)) { throw "No backup folder at $source. Check -BackupPath (ASRMS_BACKUP_PATH in backend\.env)." }
    $newest = Get-ChildItem -Path $source -Filter 'asrms-*.zip' -File | Sort-Object Name -Descending | Select-Object -First 1
    if ($null -eq $newest) { throw "No backup (asrms-*.zip) in $source." }
    $name = $newest.Name
    Write-Host "Newest backup: $($newest.FullName) ($([math]::Round($newest.Length / 1KB, 1)) KB, $($newest.LastWriteTime))"

    # 2. A different, encrypted drive.
    $destRoot = [System.IO.Path]::GetPathRoot([System.IO.Path]::GetFullPath($Destination))
    $sourceRoot = [System.IO.Path]::GetPathRoot([System.IO.Path]::GetFullPath($source))
    if (-not (Test-Path $destRoot)) { throw "The drive $destRoot is not connected. Plug in and unlock the external drive." }
    if ($destRoot -eq $sourceRoot) { throw "The destination is on the same drive as the backups ($destRoot). Use the external drive." }
    if ($destRoot -eq [System.IO.Path]::GetPathRoot($env:SystemRoot)) { throw "The destination is the Windows drive ($destRoot). Use the external drive." }

    $state = Get-BitLockerState $destRoot
    if ($state -ne 1 -and $state -ne 3) {
        $what = if ($state -eq 2) { 'is OFF' } else { 'could not be confirmed' }
        if (-not $SkipEncryptionCheck) {
            throw "BitLocker on $destRoot $what. The backup holds personal data: only copy it to a BitLocker-encrypted drive (see OFFSITE-AND-BITLOCKER.md)."
        }
        Write-Warning "BitLocker on $destRoot $what; copying anyway because -SkipEncryptionCheck was given."
    }

    # 3. The original is sound.
    Write-Host 'Checking the backup against its manifest...'
    $manifest = Test-BackupSet $newest.FullName

    # 4. Copy and verify.
    New-Item -ItemType Directory -Path $Destination -Force | Out-Null
    $target = Join-Path $Destination $name
    $sourceHash = (Get-FileHash -Path $newest.FullName -Algorithm SHA256).Hash
    if ((Test-Path $target) -and (Get-FileHash -Path $target -Algorithm SHA256).Hash -eq $sourceHash) {
        Write-Host "$name is already on the drive (identical)."
    } else {
        $partial = "$target.partial"
        Copy-Item -Path $newest.FullName -Destination $partial -Force
        if ((Get-FileHash -Path $partial -Algorithm SHA256).Hash -ne $sourceHash) {
            Remove-Item $partial -Force
            throw 'The copy is not identical to the original (SHA-256 differs). Try again; if it repeats, the drive may be failing.'
        }
        Move-Item -Path $partial -Destination $target -Force
    }
    Write-Host 'Checking the copy against its manifest...'
    Test-BackupSet $target | Out-Null

    # 5. Keep the newest copies on the drive.
    $copies = Get-ChildItem -Path $Destination -File | Where-Object { $_.Name -match '^asrms-\d{8}-\d{4}(-\d+)?\.zip$' } | Sort-Object Name -Descending
    $removed = @($copies | Select-Object -Skip $Keep)
    foreach ($old in $removed) { Remove-Item -Path $old.FullName -Force }
    $kept = @($copies).Count - $removed.Count

    # 6. Record it.
    $summary = '{0} ({1} KB, made {2}) -> {3}, SHA-256 and manifest verified, {4} copies on the drive' -f `
        $name, [math]::Round($newest.Length / 1KB, 1), $manifest.created_at, $Destination, $kept
    Write-BackupLog 'OK' $summary
    Write-Host "Off-machine copy done: $summary"
    exit 0
} catch {
    $reason = $_.Exception.Message
    Write-BackupLog 'FAILED' ("{0}: {1}" -f ($(if ($name) { $name } else { '(no backup)' })), $reason)
    Write-Host "Off-machine copy FAILED: $reason" -ForegroundColor Red
    exit 1
}
