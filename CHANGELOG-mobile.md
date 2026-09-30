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

## Circle detail fix (/lessons/:id)

- The page claims its header while the circle loads or fails (`MobileLessonPending`), and waits for the hall-conflict
  check before drawing the body (a skeleton in the same shape), so the notice no longer pushes the tabs down. CLS at
  320px: 0.236 → 0.
- Shared: `MobilePage` claims the header in a layout effect, so the default header is gone before the first paint on
  every page that declares its own (this was the 0.05 shift on the student profile too). `MSegmented` truncates the
  label instead of the button, whose 44px hit area was reported as clipped text.

## Memorization (/evaluation, /evaluation/:sessionId) — Memorization.dc.html

- Home: the page header carries the title; the desktop band and tabs become one segmented control (يومي / شهري) on
  the same `tab` param. The daily tab is the attendance day view (already mobile).
- Entry form (the session sheet), `MobileScoreEntry`: header with the circle name, back to the day and a "set a score
  for everyone" action (bottom sheet); breadcrumb; one session line (date · time · hall · teacher); scored n of N with a
  bar; student strip (avatar with the saved / changed / not-scored dot, name linking to the profile, n of N, state
  pill, a roster sheet to jump to any student with their totals); the four 0–10 scores as 48px selects with the total
  pill (gold when one is below the circle's threshold); today's ranges as (type | surah), (from ayah | to ayah) and a
  preview line with the surah in font-quran; note textarea; low-score suggestions with فتح صعوبة; send to guardian.
- Sticky حفظ التسميع (unsaved dot) and a 48px "next student" square: saves when the current row is finished and
  changed (the same save call as desktop, which stores every complete row), then moves on. Feedback is a 4-second
  toast; loading is a skeleton of the form.
- Monthly tab: circle | month selectors, the hint, and the same one-student form without ranges.
- Not in the app: the spec's grade buttons (ممتاز ٥ … إعادة ٢) — scores are four criteria out of 10; verse text for
  the preview (the API has surah names and ayah counts only, so the preview names the surah and range); a recitation
  history view (the header's history icon).

## Exams (/exams) — Exams.dc.html

- `MobileExams` under `lg:hidden`, same `['exams', filters]` query and URL params. Segmented الكل / القادمة /
  للتصحيح / المنتهية on the existing `status` filter (published / closed / graded; drafts stay under الكل), type chips
  on `type`.
- Cards: date block (day number and short month, the paper exam's day or the online window's start), title (links to
  the exam), status pill, circle (link) or package · attempts, type · marks, countdown pill (مفتوح الآن / اليوم /
  بعد n يوم) on published exams. Two secondary buttons: قائمة الطلاب (grading tab) and النتائج (الأسئلة on drafts).
  Awaiting-grading cards are gold-tinted and their first button is إدخال النتائج. FAB اختبار جديد (existing dialog).
  Skeleton, empty and error states.
- The spec's primary إدخال النتائج on the gold card is a secondary here: the FAB is the screen's one primary.
- Not in the app: reminding guardians of an exam (تذكير أولياء الأمور) — the API only sends results.
