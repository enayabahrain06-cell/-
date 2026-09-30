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

## More (المزيد) — More.dc.html

- The المزيد sheet (bottom-nav المزيد, and the app-bar avatar) is the spec's §4.4 page: profile card, the sidebar
  groups with only the pages the user can open, language row, install row, logout. There is no `/more` route (spec
  rule 7 keeps routes as they are); the sheet opens over the current root tab and المزيد shows as active while open.
- Count pills now cover every open alert type, on the section that handles it: الباقات والتسجيل (registration
  requests), الحلقات والقاعات (hall conflicts + circles without a teacher), الحضور (repeated absence), القرعة
  (draws awaiting approval), المدفوعات (overdue invoices). Same `['alerts-count']` query as the bell.

## Login (/login) — Login.dc.html

- `AuthLayout` below lg: a deep-emerald header replaces the thin ornament band and the logo row — logo tile, app
  name, today's Hijri date, the language pill (44px) and one Qur'an line in `font-quran`, with a 10% khatam star in
  the corner. The desktop split screen is unchanged.
- Page: title Amiri 28, one-line welcome, segmented الموظفون / الطلاب وأولياء الأمور (44px items, active in
  brand-700), same forms and validation. إظهار and the OTP change-number / resend buttons get 44px hit areas; footer
  links become 44px rows (قدّم طلب تسجيل bold brand, تتبع طلب التسجيل muted ink/65).
- The OTP code stays the existing single 6-digit field (spaced digits, `one-time-code`) with its resend timer.

## Register (/register) — Register.dc.html

- `PublicLayout` takes an optional `mobile` header: below lg the deep brand header gives way to the mobile page
  header (back to /login, title, language pill). The certificate verification page does not pass it and is unchanged.
- Progress: 4px step segments (done emerald, current gold) and one line "الخطوة ١ من ٣ · الطالب"; the numbered
  desktop stepper and the page hero are hidden below lg, and so is the "step n of N" line inside each step card.
- `StepFooter` (and the new `StepBar` for the first step) is the sticky action bar below lg: السابق secondary,
  التالي / إرسال الطلب primary filling the row at 48px. Cards use 16px padding; the footer links are 44px.
- The flow stays the app's own (spec rule 7): الطالب (track + birth date) → الباقة → البيانات (+ placement test,
  result and review when the package has one) → done screen with the request number and a link to /track/:no.
- Not in the app: CPR, school and a separate guardian step in the public form (the API takes gender, birth date,
  package, names, phones, level, photo, notes); landing on /track?ref= (the done screen links to /track/:no).

## Track (/track, /track/:no) — Track.dc.html

- Mobile header (back, تتبع الطلب, language pill); the search card is the existing form (request no. + guardian
  phone, primary عرض الحالة). Both fields must match on the server, so "request no. or phone" stays "and".
- Result below lg: student name, request number · package, status pill (pending/lottery warn, waitlist info,
  accepted/enrolled ok, rejected err), the status sentence, waitlist position, then a vertical timeline (done =
  emerald dot, current = gold ring, pending = hollow; rejected decision in clay) with dates, and the reason.
- Lapis note with the authority phone from `/public/settings` (tel: link, Latin digits, `dir=ltr`).
- Not in the backend: the spec's five stages (اكتمال البيانات، تحديد الحلقة، السداد); the request only has
  created / decided dates, so the timeline shows the app's three steps.

## Registration requests (/packages?tab=requests) — Requests.dc.html

- `MobileRequests` under `lg:hidden`; the band, desktop tabs, filter bar and list are unchanged under `hidden lg:block`.
  Same `['registrations', filters]` query, mutations and dialogs (accept with circle + level, ID card, reject with
  reason, bulk accept) as desktop.
- Page header الباقات والتسجيل with a share action that opens the public form, breadcrumb, segmented الباقات /
  طلبات التسجيل (the page's `tab` param).
- Search (Enter commits `search`) + filter button (package select and قبول جماعي in a bottom sheet); status chips
  on the same `status` param (المعلقة، قائمة الانتظار، بانتظار القرعة، المسجّلة، المرفوضة، الكل), the active chip
  shows its count.
- Cards: avatar, name, age · track · level · guardian, request no. · phone (Latin, `dir=ltr`), status pill; a
  bg-page line with the package and an age-of-request pill (gold after 7 days pending), placement score and
  recommended / confirmed level, "no photo" when missing; rejection reason on clay. Actions: قبول (tinted, opens the
  accept dialog where the circle is chosen), 44px انتظار / ID card / reject (clay, opens the reason confirm) icon
  buttons, or ملف الطالب once enrolled. Skeleton cards, designed empty state, pagination, 4-second toast.
- The accept buttons are tinted secondaries, not bg-brand-700: a list of cards cannot each hold the screen's one
  primary; this tab has no primary.
- Not in the backend: per-status counts for every chip (the list returns only the filtered total), a suggested
  circle per request without one call per card (the matcher runs in the accept dialog), "طلب استكمال" (request
  missing data) and an incomplete-request flag.

## Quick register (/enrollment) — QuickRegister.dc.html

- Same page and form (one `QuickEnrollForm`, same state, validation and API call); below lg only classes change.
  The band is hidden (the shell's page header تسجيل سريع + breadcrumb replaces it), the single / Excel tabs are a
  full-width segmented control with 44px items.
- Sections are grouped by 12px labels (الطالب، ولي الأمر، الباقة والحلقة، الدفع) in 16px-padded cards; package and
  circle pickers keep their seats-left / free-seats captions.
- Total row (bg-brand-50: package name, price or مجانية, waitlist note) above the sticky bar once a package is picked.
- Sticky bar: حفظ وتسجيل (primary; the label follows the mode, e.g. حفظ بدون باقة / قائمة الانتظار) and حفظ وإضافة
  آخر (secondary). The circle page's "add student" dialog uses the same form and keeps its inline buttons.
- The ID card reader bar is hidden below lg: the reader is a USB device on the reception PC, and its button is a
  second bg-brand-700 on the screen.
- Differs from the spec: payment is recorded in the same save (the existing "record a cash payment" option) rather
  than "حفظ وتسجيل دفعة → payments/new"; the halaqa capacity shows as free seats per circle.

## Packages (/packages) — Packages.dc.html

- `MobilePackageList` under `lg:hidden`; the desktop grid is unchanged under `hidden lg:block`. Same `['packages']`
  query and `PackageFormDialog` (edit, and new from the FAB باقة جديدة, the screen's one primary).
- Cards: name, status pill (مفتوحة / مسودة / مغلقة) and, for `packages.manage`, a 44×26 on/off switch that sends
  the same package update as the edit dialog with only `status` (open ↔ closed); price 22px + days · start date;
  pills for seats (gold "full" when full), track, ages and time; pending / waitlist pills open the requests tab
  filtered to the package; تعديل secondary. Non-open packages at 75% opacity. Skeleton cards, empty state with a
  create action, error toast when the switch fails.
- Not in the backend: الاشتراكات النشطة and الخصومات tabs (no subscriptions or discounts API; the segmented control
  keeps the app's الباقات / طلبات التسجيل), a "recommended" package flag, feature lists per package, a billing period
  (packages have a price per term).
