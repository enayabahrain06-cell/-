<#
  Checks one reception PC for ID card reading and says what is missing. Needs no administrator rights.
  Personal data from the card is never shown: only whether names were read.
#>
$ErrorActionPreference = 'SilentlyContinue'
$ServicePath = 'C:\Program Files (x86)\CIO\GCC CardRead Server'
$allOk = $true

function Pass($en, $ar) { Write-Host "  [OK]   $en" -ForegroundColor Green; Write-Host "         $ar" -ForegroundColor DarkGreen }
function Fail($en, $ar, $fixEn, $fixAr) {
    $script:allOk = $false
    Write-Host "  [!!]   $en" -ForegroundColor Red
    Write-Host "         $ar" -ForegroundColor DarkRed
    Write-Host "         Fix: $fixEn" -ForegroundColor Yellow
    Write-Host "         الحل: $fixAr" -ForegroundColor Yellow
}

Write-Host ''
Write-Host 'ID card reader check  /  فحص قارئ البطاقة' -ForegroundColor Cyan
Write-Host ''

# 1. Reader
$readers = @(Get-PnpDevice -Class SmartCardReader -PresentOnly)
if ($readers.Count -gt 0) { Pass ("Card reader: " + ($readers[0].FriendlyName)) 'قارئ البطاقة موصول' }
else { Fail 'No card reader is connected.' 'لا يوجد قارئ بطاقة موصول.' 'Plug in the USB card reader.' 'وصّل قارئ البطاقة عبر USB.' }

# 2. iGA program
$svc = Get-Service SCardReadServer
if (-not $svc) {
    Fail 'iGA GCC CardRead Server is not installed.' 'برنامج GCC CardRead Server غير مثبّت.' 'Run the iGA installer (see iGA-installer folder).' 'ثبّت برنامج الهيئة (انظر مجلد iGA-installer).'
} elseif ($svc.Status -ne 'Running') {
    Fail 'iGA GCC CardRead Server is installed but not running.' 'البرنامج مثبّت لكنه لا يعمل.' 'Restart the PC, or start the "SCardReadServer" service.' 'أعد تشغيل الجهاز أو شغّل خدمة SCardReadServer.'
} else { Pass 'iGA GCC CardRead Server is running' 'برنامج الهيئة يعمل' }

# 3. Bahrain update
$bah = Join-Path $ServicePath 'Extensions\BAH\BH.CIO.Smartcard.Bahrain.dll'
if (Test-Path $bah) {
    if ((Get-Item $bah).LastWriteTime.Year -ge 2025) { Pass 'Bahrain update is installed' 'تحديث البحرين مثبّت' }
    else { Fail 'The Bahrain part is old (new Bahrain cards will not read).' 'ملفات البحرين قديمة (لن تُقرأ البطاقات الجديدة).' 'Run 1-Setup.cmd.' 'شغّل الملف 1-Setup.cmd.' }
}

# 4. Test read
if ($svc -and $svc.Status -eq 'Running') {
    $body = '{"ReadCardInfo":true,"ReadPersonalInfo":true,"ReadAddressDetails":false,"ReadBiometrics":false,"ReadEmploymentInfo":false,"ReadImmigrationDetails":false,"ReadTrafficDetails":false,"SilentReading":true,"ReaderIndex":-1,"ReaderName":"","OutputFormat":"JSON","ValidateCard":false}'
    try {
        $r = Invoke-RestMethod -Method Post -Uri 'http://localhost:5050/api/operation/ReadCard' -ContentType 'application/json' -Body $body -TimeoutSec 60 -ErrorAction Stop
        if ($r -is [string]) { $r = $r | ConvertFrom-Json }
        $named = -not [string]::IsNullOrWhiteSpace($r.EnglishFullName)
        if ($named -and $r.CardCountry -ne 'KWT') { Pass ("Test read worked (card country: " + $r.CardCountry + ")") 'نجحت قراءة البطاقة التجريبية' }
        elseif ($r.CardCountry) { Fail ("The card was found but read without names (country " + $r.CardCountry + ").") 'وُجدت البطاقة لكن بلا أسماء.' 'Run 1-Setup.cmd.' 'شغّل الملف 1-Setup.cmd.' }
        else { Fail 'No card could be read.' 'تعذّرت قراءة البطاقة.' 'Insert a Bahrain ID card and run this check again.' 'أدخل بطاقة هوية بحرينية وأعد الفحص.' }
    } catch {
        Fail 'No card in the reader (or it could not be read).' 'لا توجد بطاقة في القارئ أو تعذّرت قراءتها.' 'Insert a Bahrain ID card and run this check again.' 'أدخل بطاقة هوية بحرينية وأعد الفحص.'
    }
}

Write-Host ''
if ($allOk) { Write-Host 'Everything is ready. In the system press "Read ID card".' -ForegroundColor Green; Write-Host 'الجهاز جاهز. اضغط "قراءة البطاقة" في النظام.' -ForegroundColor Green }
else { Write-Host 'Fix the items marked [!!], then run this check again.' -ForegroundColor Yellow; Write-Host 'أصلح البنود المعلَّمة [!!] ثم أعد الفحص.' -ForegroundColor Yellow }
Write-Host ''
