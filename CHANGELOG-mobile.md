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
