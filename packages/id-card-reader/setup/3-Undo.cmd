@echo off
rem Puts back the original iGA files saved by 1-Setup.cmd. Approve the Windows prompt.
rem يعيد ملفات الهيئة الأصلية التي حفظها 1-Setup.cmd. وافق على نافذة Windows.
chcp 65001 >nul
net session >nul 2>&1
if errorlevel 1 (
  powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b
)
title ID card reader undo
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0files\Update-IgaBahrain.ps1" -Restore
pause
