@echo off
rem Builds the USB kit for reception PCs: this setup folder plus the five iGA SDK files the update needs.
rem   make-kit.cmd <path to the iGA SDK folder> [output folder]
rem The SDK is licensed by iGA and never committed; the kit it produces must stay out of git too.
setlocal
set SDK=%~1
set OUT=%~2
if "%SDK%"=="" (echo Usage: make-kit.cmd ^<iGA SDK folder^> [output folder] & exit /b 1)
if "%OUT%"=="" set OUT=%~dp0..\..\..\ID-Card-Reader-Kit

for %%f in (BH.CIO.Smartcard.Bahrain.dll BH.CIO.Smartcard.Bahrain.Lookup.dll BerTlv.dll Utils.dll BH.CIO.Smartcard.IDCardManager.dll.config) do (
  if not exist "%SDK%\%%f" (echo Missing in the SDK folder: %%f & exit /b 1)
)

if not exist "%OUT%\files\cards" mkdir "%OUT%\files\cards"
if not exist "%OUT%\iGA-installer" mkdir "%OUT%\iGA-installer"
copy /y "%~dp01-Setup.cmd" "%OUT%\" >nul
copy /y "%~dp02-Check.cmd" "%OUT%\" >nul
copy /y "%~dp03-Undo.cmd" "%OUT%\" >nul
copy /y "%~dp0GUIDE.html" "%OUT%\" >nul
copy /y "%~dp0NOTES.txt" "%OUT%\" >nul
copy /y "%~dp0files\*.ps1" "%OUT%\files\" >nul
copy /y "%~dp0iGA-installer\*.txt" "%OUT%\iGA-installer\" >nul
for %%f in (BH.CIO.Smartcard.Bahrain.dll BH.CIO.Smartcard.Bahrain.Lookup.dll BerTlv.dll Utils.dll BH.CIO.Smartcard.IDCardManager.dll.config) do copy /y "%SDK%\%%f" "%OUT%\files\cards\" >nul

echo Kit ready: %OUT%
echo Copy it to each reception PC (USB) and open GUIDE.html.
