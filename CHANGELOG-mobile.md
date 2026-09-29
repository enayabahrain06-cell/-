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
