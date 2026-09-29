@echo off
rem Installs the Bahrain update into iGA's GCC CardRead Server. Double-click it; approve the Windows prompt.
rem تثبيت تحديث البحرين في برنامج الهيئة. انقر مرتين ووافق على نافذة Windows.
chcp 65001 >nul
net session >nul 2>&1
if errorlevel 1 (
  powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b
)
title ID card reader setup
set LOG=%~dp0setup-log.txt
powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Transcript -Path '%LOG%' -Force | Out-Null; try { & '%~dp0files\Update-IgaBahrain.ps1' -SdkPath '%~dp0files\cards' } catch { Write-Host ('ERROR: ' + $_.Exception.Message) -ForegroundColor Red } finally { Stop-Transcript | Out-Null }"
echo.
echo Log saved to %LOG%
pause
