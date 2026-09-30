# Mobile redesign changelog

Mobile only (below `lg`, 1024px), following `mobile-redesign-spec.md`. Desktop (lg+) is unchanged. Each page is checked
by pixel-diffing desktop screenshots at 1024 and 1440 before and after; the only differences are live values
(clock, "n hours ago").

The artboards (`*.dc.html`) were not in the repo, so screens follow the spec's written description. Routes stay
as they are (spec rule 7): /halaqat is /lessons, /requests is /packages?tab=requests, /register/quick is
/enrollment, /memorization is /evaluation, /excellence is /honor. Spec features the backend does not have yet are
left out and listed under the page.

## Shell and tokens

- `@theme`: chart-present / late / absent / excused / grid colors, `radius-card` (12px), `radius-ctl` (10px),
  `shadow-card`, `shadow-popover` (spec §3). New `no-scrollbar` utility for chip rows.
- `components/mobile/`: `MobileAppBar`, `MobilePageHeader` + breadcrumb, `MobileBottomNav`, `MoreSheet`,
  `BottomSheet`, `StickyActionBar` (+ `StickySquare`), `Fab`, and the §4.7 atoms (card, pill, chips, segmented,
  list rows, avatar, search, empty state, skeletons).
- `AppLayout`: below lg the desktop header is hidden and the mobile shell renders instead. Root tabs get the app bar
  and the bottom nav; every other page gets back + title + a one-line breadcrumb (a page can portal its own header
  with `<MobilePage>`). The old hamburger drawer is replaced by the bottom nav and the المزيد sheet.
- Bottom-nav tabs come from permissions (the app has no accountant or read-only role): الرئيسية · الحضور ·
  الحلقات · الطلاب, and when one is not allowed the next of المدفوعات, التقارير, الرسائل, الحفظ, المعلمون fills it.
- المزيد sheet: profile (name, roles · track), the sidebar groups with only the pages the user can open, a count
  pill on الباقات والتسجيل for open registration-request alerts, language (العربية · English), logout.
- Bell: gold dot when there are open dashboard alerts (`/alerts`), links to the dashboard alerts.
- New icons: home, bell, more, plus, filter, sort, history, globe, wallet.

## Dashboard (/) — Main.dc.html

- `MobileDashboard` under `lg:hidden`; the desktop page is unchanged under `hidden lg:block`. Both use the one
  `['dashboard', locale]` query.
- Compact banner: greeting (Amiri 24, gold-300), one date line (Gregorian · Hijri), then track chip · last update ·
  44px refresh (labelled). Corner khatam star at 10%.
- One primary button, تسجيل الحضور (/attendance), and up to four icon tiles by permission: تسجيل سريع,
  طلبات التسجيل, الحلقات والقاعات, تسجيل دفعة (opens the existing payment dialog).
- حلقات اليوم cards: name, time · hall · students, status pill (رُصد الحضور n/n, لم يُرصد بعد, hall problem,
  cancelled) and a 6px progress bar; each opens the circle (or its attendance sheet without lessons.view).
- نظرة سريعة: 2-column KPI cards linking with the desktop filters; attendance-7-days card has a 4px bar; the money
  card spans both columns with a gold "n عليهم مستحقات" pill. Without the registrations KPI (teachers) the today
  count takes its place.
- Alerts (the bell's target, #alerts): the five most urgent with a priority pill; each row opens the alert's
  subject (circle, student, requests, wallet, lottery, exam). Actions such as مراسلة ولي الأمر stay on desktop.

## Attendance (/attendance, /attendance/:id) — Attendance.dc.html

- Day page (root tab, also the evaluation "daily" tab): date stepper with 44px previous/next, date input, one date
  line (Gregorian · Hijri) with a "today" link, session cards (time · hall · teacher, taken / not taken / cancelled
  pill, hall-changed pill, present · absent count).
- Sheet: page header with the circle name, back to the day, breadcrumb الرئيسية › الحضور › circle. Circle selector
  (the other sessions that day) | date selector side by side; five live tiles (حاضر / متأخر / غائب / معذور /
  لم يُحدد) with the chart state colors; helper line and a الكل حاضر chip (same confirm + mark-all call).
- Rows: 36px avatar, name (links to the profile), current memorization, absence-sent note, and four 44×40 state
  buttons with `aria-pressed`, each selected state in its color pair.
- Assignments, note and today's ranges moved to a bottom sheet, opened by the row's edit button or a long press.
- Sticky حفظ الحضور (n من N) with an unsaved dot; results and errors show as a 4-second toast.
- Shared: `MobileToast`; mobile names use `<bdi>` so Arabic names align with the page direction in English.
- Not in the app: an attendance history view (the spec's history icon) and excuse attachments.

## Circles and halls (/lessons, /lessons/:id) — Halaqat.dc.html, HalaqaDetail.dc.html

- List (root tab): title, segmented الحلقات / القاعات / الحجوزات (the existing tabs), search + filter button (status in
  a bottom sheet), track chips (الكل n، بنين، بنات، مبكر) on the same `gender` / `status` / `search` params.
- Circle cards: name, a "today" pill when the circle meets today (otherwise its days) with the start time, teacher ·
  hall, a 4px seat bar with n من c, track and status pills. FAB حلقة جديدة (existing dialog).
- Halls tab: hall cards with calendar / map / edit / on-off as secondary buttons; FAB قاعة جديدة. Bookings tab: list
  rows with a labelled delete button; FAB حجز جديد.
- Detail: header with ⋯ (edit, change hall in a sheet), breadcrumb, summary card (teacher, status, hall · days · time,
  package, mini-KPIs students n/c · free seats · upcoming sessions), hall-conflict notice, segmented الطلاب / الجدول
  (sessions link to their attendance sheet). Sticky: سجّل حضور اليوم when the circle meets today, else إضافة طالب;
  48px message button (messages send tab).
- Not in the app: per-circle attendance % and memorization tabs, morning/evening chips, preselected guardians in
  messages.
- Shared: the action bar is pinned to the bottom edge (spacer in the flow) and the FAB reserves space after lists.

## Installable app (PWA)

- `public/manifest.webmanifest`: Arabic name, RTL, `standalone`, portrait, deep-emerald theme, page-colored
  splash, shortcuts (الحضور، تسجيل سريع، الطلاب). Icons 192 / 512 / maskable 512 and a 180 Apple touch icon, drawn
  from the logo mark.
- `public/sw.js`: caches only Vite's hashed `/assets/*` (cache first), the app shell for offline start (network
  first) and Google Fonts; `/api` and `/media` are never cached. With the Vite dev server it caches nothing.
- `index.html`: manifest, Apple web-app tags, `viewport-fit=cover` (the bottom nav clears the home indicator).
- المزيد sheet: "تثبيت التطبيق على الجوال" — an install button where the browser offers it (Android Chrome, Edge),
  the Share → Add to Home Screen hint on iPhone, hidden once installed.
- Chrome's installability check (`Page.getInstallabilityErrors`) reports no errors on :5180. On phones the site
  must be served over HTTPS (localhost is the only exception).

## Payments & wallets (/payments) — Payments.dc.html

- `MobilePayments` under `lg:hidden`; the desktop page is unchanged under `hidden lg:block`. The payment, invoice,
  refund and adjustment dialogs stay in `PaymentsHomePage` and open from both layouts.
- Header: back + title + ⋯ (فاتورة جديدة، تسجيل استرداد، تسوية رصيد, by permission). Save messages show as a
  4-second toast (its own state, so the desktop notice is untouched).
- Two KPI cards from the same `['finance', this year]` query as the desktop overview: المحصَّل هذا الشهر with the
  change on last month, and المستحق (gold-tinted, n عليهم مستحقات) which opens المتأخرات. One column below 360px.
- Segmented آخر العمليات / المتأخرات / المحافظ on the same `?tab=` param (payments / invoices / wallets).
  - آخر العمليات: this month's payments (same query key as the desktop tab): student, time · method · invoice,
    amount, مسدد pill. A row opens a sheet with the receipt facts, الإيصال (PDF) and إعادة الإرسال, and a link to
    the student's wallet. Note "الإيصال يُطبع من صفحة العملية" under the list.
  - المتأخرات: invoices with chips المتأخرة / مفتوحة / جزئية / مسددة / الكل; due date · package, amount (clay when
    overdue), status pill (متأخرة / مفتوحة / جزئي / مسدد). Rows open the student's wallet.
  - المحافظ: students with dues from the finance report (amount owed in clay), linking to the wallet.
- Sticky تسجيل دفعة (the one primary). New shared file `components/mobile/MPager.tsx` (44px previous / next).
- Desktop only: the refunds list, the finance report tab (period, package and track filters, exports) and
  cancelling an invoice.

## Messages (/messages, compose = /messages?tab=send) — Messages.dc.html

- Below lg the page band and segmented tabs are replaced by a chip row of the same tabs (same `?tab=` param) under
  the shell's page header.
- Compose (send tab): `MobileCompose` under `lg:hidden`. Its state, validation and send call come from
  `useSendForm` (moved out of `SendTab` unchanged, so the desktop form and the mobile screen share one hook).
  Card: students / phone numbers (when allowed), recipient tags (removable, 44px remove target) with a 48px
  student search, recipient segmented (ولي الأمر / الطالب / كلاهما), language, message textarea with the n / 1000
  counter. آخر الرسائل: the last five log rows with status pills and a الكل link to the log. Sticky
  "إرسال إلى n ولي أمر" (Arabic plural forms; student / both / numbers variants). Result as a 4-second toast.
- Log tab: `MobileLog` under `lg:hidden` (state stays in `LogTab`): phone search + filter sheet (type, from, to),
  status chips with counts, resend-all as a secondary button, rows (name, phone · type, time, error, status pill)
  opening the existing details dialog, 44px resend on failed rows, `MPager`.
- Inbox, templates, rules and WhatsApp tabs render their existing (already stacking) layouts on mobile.
- Not in the app: channel choice (SMS / in-app; only WhatsApp exists), the template row (manual send has no
  per-student placeholders such as {اسم_الطالب}), recipients prefilled from a student, circle or overdue list
  (the send tab takes no query params), delivery and reply counts per sent message.
- Open: the inbox tab still shows one primary قبول button per excuse card on mobile.

## Reports (/reports, /reports?report=…) — Reports.dc.html

- Page band hidden below lg; the shell header carries the title (the report's title inside a report, back to the
  catalog, breadcrumb الرئيسية › التقارير › report).
- Catalog: `MobileCatalog` under `lg:hidden` (catalog query and search state shared with desktop). Period chips
  (هذا الشهر / الشهر الماضي / هذا الفصل) → "نسبة الحضور اليومية": the last seven taken days as 14px bars
  (chart-present, chart-late below 80%), value above each bar, day number and weekday below (weekday hidden under
  360px), one-line insight (average and lowest day) → "توزيع الحالات": one stacked bar with 2px gaps and a
  labelled legend (count · %) → link to the full attendance report with the same period → other reports as grouped
  lists (icon badge, title, two-line description). The attendance data is the attendance report's own endpoint with
  the same query key as the viewer, fetched only below lg (`components/mobile/useBelowLg.ts`, new).
- Report viewer: period chips + filter button (sheet with the report's own filter controls, shared JSX with the
  desktop filter card) and a download icon in the header opening an export sheet (PDF / Excel, same export call).
  Report tables become card rows below lg (naming column as the title, the other columns as label / value pairs);
  the "show all" limit is shared with the table.
- `presets()` moved to `features/reports/presets.ts` (unchanged) so both layouts use it.
- Not on mobile: column sorting of report tables (desktop header buttons).

## Users & permissions (/users, /users?tab=roles) — Users.dc.html

- Page band hidden below lg; tabs المستخدمون / الأدوار والصلاحيات as a segmented control; notices as a 4-second
  toast (own state; the desktop notice is unchanged).
- Users: `MobileUsersList` under `lg:hidden` (query, debounced search, filters and the activate / deactivate
  mutation stay in `UsersList`). Search, role chips (الكل + each role) plus a معطّل chip (the `active=0`
  filter), "n مستخدم" count, rows: avatar (initials skip honorifics, as on desktop), name (+ أنت), phone
  (`dir=ltr`), role pill (مدير النظام / مشرف = info, معلم = ok, others neutral, "+n" for extra roles), معطّل pill
  and last login; deactivated users at 70% opacity. A row opens a sheet with تعديل (existing dialog) and
  تعطيل / تفعيل (same confirm dialog). Note linking to سجل التدقيق (with audit.view). FAB مستخدم جديد.
- Roles: `MobileMatrix` under `lg:hidden` (draft, dirty tracking and save stay in `PermissionMatrix`): role chips
  (a gold dot on edited roles), one card per module with a module switch (n/N) and a switch per permission
  (label + code); مدير النظام is locked. Sticky تجاهل + حفظ when there are unsaved changes.
- New shared file `components/mobile/MSwitch.tsx` (44×26 track in a 44px hit area, `role="switch"`).
- Not in the app: an accountant or read-only role (so no warn/neutral staff role pills beyond the existing roles).
