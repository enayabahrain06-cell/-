<#
.SYNOPSIS
  Lets the installed iGA "GCC CardRead Server" read newer Bahrain ID cards.

.DESCRIPTION
  The installed service's Bahrain extension (2016) knows newer Bahrain cards only by a short ATR prefix, so it
  guesses the country from a partial match, picks Kuwait, and returns empty names. This script:
    1. backs up what it changes to <service>\backup-<date>,
    2. copies the 2025 Bahrain extension DLLs from the iGA SDK folder into <service>\Extensions\BAH,
    3. replaces the BAH line of <service>\BH.CIO.Smartcard.IDCardManager.dll.config with the SDK's full ATR list,
    4. restarts the service and reads the inserted card once to check it now reports Bahrain.
  Run it from an administrator PowerShell. -Restore puts the latest backup back.

  iGA's own updated installer, when available, is the better long-term fix; this script is the stop-gap.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\Update-IgaBahrain.ps1 -SdkPath C:\Users\it\saar\cards
.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\Update-IgaBahrain.ps1 -Restore
#>
param(
    [string]$SdkPath = (Join-Path $PSScriptRoot '..\..\cards'),
    [string]$ServicePath = 'C:\Program Files (x86)\CIO\GCC CardRead Server',
    [switch]$Restore
)

$ErrorActionPreference = 'Stop'
$ServiceName = 'SCardReadServer'
$ConfigName = 'BH.CIO.Smartcard.IDCardManager.dll.config'
# The 2025 Bahrain extension and the helpers it needs. Everything else in the service stays as installed.
$ExtensionFiles = 'BH.CIO.Smartcard.Bahrain.dll', 'BH.CIO.Smartcard.Bahrain.Lookup.dll', 'BerTlv.dll', 'Utils.dll'

$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { throw 'Run this from an administrator PowerShell (right-click PowerShell > Run as administrator).' }
if (-not (Test-Path (Join-Path $ServicePath 'SCardReadWebApi.exe'))) { throw "iGA GCC CardRead Server not found in $ServicePath" }

$bah = Join-Path $ServicePath 'Extensions\BAH'
$config = Join-Path $ServicePath $ConfigName

function Restart-CardService {
    Write-Host "Starting $ServiceName..."
    $svc = Get-Service $ServiceName
    if ($svc.Status -eq 'Running') { Restart-Service -Name $ServiceName -Force } else { Start-Service -Name $ServiceName }
    (Get-Service $ServiceName).WaitForStatus('Running', [TimeSpan]::FromSeconds(30))
    Write-Host "$ServiceName is running."
}

# Stop-Service returns before the service's process has let go of its DLLs; wait for it (then force it).
function Stop-ServiceProcess {
    $exe = Join-Path $ServicePath 'SCardReadWebApi.exe'
    for ($i = 0; $i -lt 20; $i++) {
        $p = Get-Process -Name SCardReadWebApi -ErrorAction SilentlyContinue | Where-Object { $_.Path -eq $exe }
        if (-not $p) { return }
        Start-Sleep -Seconds 1
    }
    $p | Stop-Process -Force
    Start-Sleep -Seconds 2
}

function Test-CardRead {
    $body = @{
        ReadCardInfo = $true; ReadPersonalInfo = $true; ReadAddressDetails = $false; ReadBiometrics = $false
        ReadEmploymentInfo = $false; ReadImmigrationDetails = $false; ReadTrafficDetails = $false
        SilentReading = $true; ReaderIndex = -1; ReaderName = ''; OutputFormat = 'JSON'; ValidateCard = $false
    } | ConvertTo-Json
    try {
        $r = Invoke-RestMethod -Method Post -Uri 'http://localhost:5050/api/operation/ReadCard' -ContentType 'application/json' -Body $body -TimeoutSec 60
        if ($r -is [string]) { $r = $r | ConvertFrom-Json }
        $hasName = -not [string]::IsNullOrWhiteSpace($r.EnglishFullName)
        Write-Host ("Card check: country={0}, name read={1}" -f $r.CardCountry, $hasName)
        # Before the patch a newer Bahrain card came back as KWT with empty names.
        if ($hasName -and $r.CardCountry -ne 'KWT') { Write-Host 'OK: the card was read with names.' -ForegroundColor Green }
        else { Write-Host 'The card still reads without names. Is a Bahrain ID card in the reader?' -ForegroundColor Yellow }
    } catch {
        Write-Host "Card check skipped: $($_.Exception.Message) (insert a card and run the check again)." -ForegroundColor Yellow
    }
}

if ($Restore) {
    $backup = Get-ChildItem $ServicePath -Directory -Filter 'backup-*' | Sort-Object Name -Descending | Select-Object -First 1
    if (-not $backup) { throw 'No backup found.' }
    Write-Host "Restoring $($backup.FullName)..."
    Stop-Service $ServiceName -Force
    Copy-Item (Join-Path $backup.FullName 'BAH\*') $bah -Recurse -Force
    # Files the patch added that the original extension did not have.
    foreach ($f in $ExtensionFiles) {
        if (-not (Test-Path (Join-Path $backup.FullName "BAH\$f"))) { Remove-Item (Join-Path $bah $f) -Force -ErrorAction SilentlyContinue }
    }
    Copy-Item (Join-Path $backup.FullName $ConfigName) $config -Force
    Restart-CardService
    Write-Host 'Restored.' -ForegroundColor Green
    return
}

$SdkPath = (Resolve-Path $SdkPath).Path
foreach ($f in $ExtensionFiles + $ConfigName) {
    if (-not (Test-Path (Join-Path $SdkPath $f))) { throw "Missing in the SDK folder ${SdkPath}: $f" }
}

# The SDK's full Bahrain ATR list (includes the newer cards the installed list lacks).
$sdkConfig = Get-Content (Join-Path $SdkPath $ConfigName) -Raw
$bahLine = [regex]::Match($sdkConfig, '<add key="BAH" value="[^"]*"\s*/>').Value
if (-not $bahLine) { throw "No BAH ATR list in $SdkPath\$ConfigName" }

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupDir = Join-Path $ServicePath "backup-$stamp"
Write-Host "Backing up to $backupDir..."
New-Item -ItemType Directory -Path (Join-Path $backupDir 'BAH') -Force | Out-Null
Copy-Item (Join-Path $bah '*') (Join-Path $backupDir 'BAH') -Recurse -Force
Copy-Item $config $backupDir -Force

try {
    Write-Host "Stopping $ServiceName..."
    Stop-Service $ServiceName -Force
    Stop-ServiceProcess

    foreach ($f in $ExtensionFiles) {
        Copy-Item (Join-Path $SdkPath $f) $bah -Force
        Write-Host "  updated Extensions\BAH\$f"
    }

    $current = Get-Content $config -Raw
    $updated = [regex]::Replace($current, '<add key="BAH" value="[^"]*"\s*/>', $bahLine)
    [IO.File]::WriteAllText($config, $updated, (New-Object Text.UTF8Encoding($false)))
    Write-Host "  updated the BAH ATR list in $ConfigName"
} catch {
    Write-Host "FAILED: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host "The backup is in $backupDir; run with -Restore to put it back."
    throw
} finally {
    # Never leave reception without a card service, whatever happened above.
    Restart-CardService
}

Test-CardRead
Write-Host "Done. To undo: .\Update-IgaBahrain.ps1 -Restore"
