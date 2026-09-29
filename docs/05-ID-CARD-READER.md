# ID card reader setup

Only the PCs that read Bahrain ID cards need this (usually reception). Every other PC uses the system in the
browser as usual, and staff can always type the details by hand.

## What each card-reading PC needs (one time)

1. **A USB smart card reader.** Any standard PC/SC reader works (the tested one is an "ACS CCID USB Reader").
   Plug it in; Windows installs the driver by itself.
2. **iGA's card program, "GCC CardRead Server".** Install it from iGA's official installer. It runs in the
   background as a Windows service (`SCardReadServer`) and appears in
   `C:\Program Files (x86)\CIO\GCC CardRead Server`. The system cannot install it for you.
3. **The Bahrain update** (until iGA ships a newer installer). The installed program is too old for newer Bahrain
   cards: it reads them as Kuwaiti with empty names. Run the patch once:
   - Copy the iGA SDK folder `cards` to the PC (USB stick). It is licensed by iGA and is not in git.
   - Easiest: copy the ready `ID-Card-Reader-Kit` folder (built with `make-kit.cmd`, see below) and double-click
     `1-Setup.cmd`. Otherwise copy `packages\id-card-reader\setup\files\Update-IgaBahrain.ps1` as well.
   - Put a Bahrain ID card in the reader.
   - Open PowerShell with **Run as administrator** and run:
     ```powershell
     powershell -ExecutionPolicy Bypass -File Update-IgaBahrain.ps1 -SdkPath <folder where you copied cards>
     ```
   - It should end with `OK: the card was read with names.`
   - To undo it: the same command with `-Restore`.
4. **Chrome or Edge.** The first time someone presses **Read ID card**, the browser may ask whether the site can
   access devices on the local network. Choose **Allow**: that is how the page talks to the card program on
   that PC.

Then open the system, insert the card and press **Read ID card**.

## Quick check

| The page says | Missing step |
|---|---|
| The card reader program isn't running | 2: install iGA's GCC CardRead Server (or start the `SCardReadServer` service) |
| No card, or no reader | 1: plug in the reader and insert the card |
| The card could not be read… the program may need updating | 3: run the Bahrain update |
| Nothing happens, or the browser blocked it | 4: allow the site to access the local network in the browser |

The kit (setup, check and undo scripts, guide, notes) is built from [packages/id-card-reader/setup](../packages/id-card-reader/setup) with `make-kit.cmd <iGA SDK folder>`.

---

## إعداد قارئ البطاقة الذكية

هذا مطلوب فقط على الأجهزة التي تقرأ بطاقات الهوية البحرينية (عادةً الاستقبال). بقية الأجهزة تستخدم النظام
من المتصفح كالمعتاد، ويمكن للموظفين دائمًا إدخال البيانات يدويًا.

### ما يحتاجه كل جهاز يقرأ البطاقات (مرة واحدة)

1. **قارئ بطاقات ذكية USB.** أي قارئ PC/SC قياسي (القارئ المجرَّب "ACS CCID USB Reader"). وصّله، وسيثبّت
   Windows التعريف تلقائيًا.
2. **برنامج هيئة المعلومات والحكومة الإلكترونية "GCC CardRead Server".** ثبّته من المثبّت الرسمي للهيئة. يعمل
   في الخلفية كخدمة Windows باسم `SCardReadServer` في المجلد
   `C:\Program Files (x86)\CIO\GCC CardRead Server`. لا يستطيع النظام تثبيته نيابةً عنك.
3. **تحديث البحرين** (إلى أن تصدر الهيئة مثبّتًا أحدث). البرنامج المثبّت قديم ولا يتعرف على بطاقات البحرين
   الجديدة، فيقرؤها كبطاقات كويتية بلا أسماء. شغّل التحديث مرة واحدة:
   - انسخ مجلد الحزمة `cards` إلى الجهاز (بذاكرة USB). الحزمة مرخّصة من الهيئة وليست في git.
   - الأسهل: انسخ مجلد `ID-Card-Reader-Kit` الجاهز (يُنشأ بالأمر `make-kit.cmd`) وانقر مرتين على `1-Setup.cmd`.
     وإلا فانسخ الملف `packages\id-card-reader\setup\files\Update-IgaBahrain.ps1` أيضًا.
   - ضع بطاقة هوية بحرينية في القارئ.
   - افتح PowerShell باختيار **تشغيل كمسؤول** ونفّذ:
     ```powershell
     powershell -ExecutionPolicy Bypass -File Update-IgaBahrain.ps1 -SdkPath <مجلد cards بعد النسخ>
     ```
   - يجب أن ينتهي بالعبارة `OK: the card was read with names.`
   - للتراجع: الأمر نفسه مع `-Restore`.
4. **Chrome أو Edge.** عند الضغط على **قراءة البطاقة** لأول مرة قد يسأل المتصفح عن السماح للموقع بالوصول إلى
   الأجهزة على الشبكة المحلية. اختر **السماح**، فبهذه الطريقة تتصل الصفحة ببرنامج البطاقة على الجهاز.

بعدها افتح النظام، وأدخل البطاقة، واضغط **قراءة البطاقة**.

### فحص سريع

| ما تعرضه الصفحة | الخطوة الناقصة |
|---|---|
| برنامج قارئ البطاقة لا يعمل | ٢: ثبّت GCC CardRead Server (أو شغّل خدمة `SCardReadServer`) |
| لا توجد بطاقة أو لا يوجد قارئ | ١: وصّل القارئ وأدخل البطاقة |
| تعذّرت قراءة البطاقة… قد يحتاج البرنامج إلى تحديث | ٣: شغّل تحديث البحرين |
| لا يحدث شيء أو حظر المتصفح الطلب | ٤: اسمح للموقع بالوصول إلى الشبكة المحلية في المتصفح |
