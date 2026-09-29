# نظام أهل القرآن — Mobile redesign spec

Companion to the Design canvas "نظام أهل القرآن — عرض الجوال" (28 phone artboards, 390×844). Scope: mobile only (< 1024px, i.e. below Tailwind `lg:`). Desktop and tablet layouts stay exactly as they are.

Stack assumed from the running app: React + Vite, Tailwind v4 (`@theme` in `src/index.css`), `html[dir="rtl"]`, existing tokens brand-\*, gold-\*, deep, page, paper, ink, danger, info, fonts Amiri (display) / IBM Plex Sans Arabic (sans) / Amiri Quran (quran).

## 1. The prompt

You are refactoring the MOBILE layout of the React + Vite + Tailwind v4 frontend in `frontend/src` for "نظام أهل القرآن" (Arabic, RTL, dir="rtl"). Read `mobile-redesign-spec.md` in the repo root first and follow it exactly. Rules:

1. Mobile only. Every change must be scoped to viewports narrower than the `lg` breakpoint (1024px). Do not alter any `lg:`/`xl:` classes, desktop sidebar, desktop tables or desktop dashboards. When a page needs a different mobile structure, render a `<MobileX>` variant under `lg:hidden` and keep the existing markup under `hidden lg:block` — never fork business logic; both variants call the same hooks/queries.
2. Use the existing design tokens only (`bg-deep`, `bg-brand-700`, `bg-brand-50`, `text-gold-300`, `text-gold-700`, `bg-page`, `bg-white`, `text-ink`, `text-ink/65`, `border-ink/10`, `text-danger`, `text-info`, `font-display`, `font-sans`, `font-quran`). Add the few new tokens listed in section 3 of the spec to `@theme` — nothing else. No new colors, no gradients, no emoji, no left-border cards.
3. Build the shared mobile shell first (section 4): `MobileAppBar`, `MobileBottomNav` (role-aware), `MobilePageHeader` (back + title + up to two icon actions), `StickyActionBar`, `Fab`, `BottomSheet`. Then convert pages in the order of section 6. Each page conversion is one commit.
4. Every screen has exactly ONE primary button (`bg-brand-700 text-white`). Everything else is secondary (`bg-white border border-ink/10 text-brand-700`) or a text link (`text-info`).
5. Tables become card lists on mobile (section 5.6). Never render a horizontally scrolling table below `lg`.
6. Touch targets ≥ 44×44px. Body text 15px, captions 13px, labels 12px, never smaller. Arabic-Indic digits everywhere except phone numbers and invoice references (use the existing number formatter). All numbers `tabular-nums`.
7. Keep every existing route, query string filter, breadcrumb and deep link. Mobile inner pages show the breadcrumb as one 12px line under the page header.
8. Accessibility: real `<button>` / `<a>` / `<input>`+`<label>`, `aria-label` on icon-only buttons, `aria-current="page"` on the active bottom-nav item, `aria-pressed` on attendance state buttons, focus ring `outline-2 outline-brand-500 outline-offset-2`.
9. After each page: run the app at 390px width in the browser, screenshot, and compare against the matching artboard name from section 6. Fix overflow, wrapping and clipped text before moving on. Run `npm run lint` and `npm run build`.
10. Do not touch the backend, the i18n keys' meaning, or the English (`lang="en"`) layout beyond mirroring the same mobile components with `ltr:` variants.

Deliver: the shell components, one mobile variant per page in the spec, the `@theme` additions, and a short CHANGELOG-mobile.md listing what changed per page.

## 2. What was wrong with the current mobile view

| Problem | Fix in the redesign |
|---|---|
| Header wastes the top row on a language pill + back arrow + hamburger; brand is invisible. | 56px app bar: logo tile + app name (start), bell + avatar (end). Language moves to المزيد / الإعدادات. |
| Greeting banner is tall (large Amiri greeting, two-line date, track chip, refresh button, "last updated" all inside). | Compact banner: greeting 24px, one date line, then one row: track chip · last-updated · 40px refresh. |
| Quick actions wrap 2-2-1 with mixed widths. | One full-width primary CTA (تسجيل الحضور) + a 4-across icon-tile row for the rest. |
| KPI cards are tall with a lot of air, hints truncate. | 2-column compact KPI cards: icon-badge + label row, value 28px, optional 4px progress or 12px hint; the money card spans both columns. |
| No persistent navigation; the desktop sidebar is hidden behind a hamburger. | Bottom navigation with 5 tabs (per role) + المزيد sheet holding the full sidebar map. |
| Today's session is only a number. | A dedicated حلقة اليوم card with status pill and progress, linking to the halaqa. |

## 3. Token mapping (design system → Tailwind theme)

The canvas uses the design-system values (left). The app already has near-equivalents (right); use those classes so nothing else in the app shifts.

| DS token | Value | Use it as (existing class) |
|---|---|---|
| surface | #F7F3EA | `bg-page` |
| surface-raised | #FFFFFF | `bg-white` |
| surface-sunken | #EFE9DC | `bg-ink/5` |
| line | #E3DCCB | `border-ink/10` |
| ink | #1F2A24 | `text-ink` |
| ink-muted | #5A655E | `text-ink/65` (never lighter for text) |
| emerald | #0F3D2E | `bg-brand-700` / `text-brand-700` |
| emerald-strong | #0A2C21 | `bg-deep` |
| emerald-soft | #E3EFE8 | `bg-brand-50` |
| gold | #B8893B | `bg-gold-500` (accent line, active tab underline) |
| gold on dark | #D9B56B | `text-gold-300` (greeting on banner) |
| gold-text | #8A6320 | `text-gold-700` |
| gold-soft | #F5EBD6 | `bg-gold-500/12` |
| lapis / lapis-soft | #1E4E79 / #E4ECF4 | `text-info` / `bg-info/10` |
| clay / clay-soft | #8C3B2E / #F6E4E0 | `text-danger` / `bg-danger/10` |
| chart-present / late / absent / excused | #2F7A5A / #B08A2E / #8C3B2E / #1E4E79 | add as tokens (below) |

Add to `@theme` (new, non-breaking):

```css
@theme {
  --color-chart-present: #2f7a5a;
  --color-chart-late:    #b08a2e;
  --color-chart-absent:  #8c3b2e;
  --color-chart-excused: #1e4e79;
  --color-chart-grid:    #e3dccb;
  --radius-card: 12px;   /* cards, sheets */
  --radius-ctl:  10px;   /* buttons, inputs on mobile */
  --shadow-card: 0 1px 2px rgba(31,42,36,.06);
  --shadow-popover: 0 8px 24px rgba(31,42,36,.12);
}
```

Type scale on mobile: greeting 24/34 Amiri 700 · page title 22/28 Amiri 700 · section title 18/28 sans 600 · KPI value 28/36 sans 600 · body 15/24 · caption 13/20 · label 12/16 600 · bottom-nav label 11/14.

Spacing: page gutter 16 · between sections 24 · between cards 12 · card padding 14×16 · 8px grid throughout.

## 4. Mobile shell (build first)

### 4.1 MobileAppBar (root tabs only)

`h-14 px-4 flex items-center justify-between border-b border-ink/10 bg-page` — start: 36px logo tile (`bg-deep text-gold-400 rounded-[10px]`) + app name (`font-display text-xl text-brand-900`); end: bell button 44px with gold dot when unread, avatar 34px (`bg-brand-50 text-brand-700`, initials).

### 4.2 MobilePageHeader (inner pages)

`h-14 ps-4 pe-2 flex items-center gap-1 border-b border-ink/10` — back link 44px (chevron points right in RTL, `rtl:rotate-0 ltr:rotate-180`), title `font-display text-[22px] leading-7 text-brand-900 truncate flex-1`, up to two 44px icon actions. Below it, optional breadcrumb: `text-xs text-ink/65 px-4 pt-2.5 truncate` with links in `text-info`.

### 4.3 MobileBottomNav

Fixed bottom, `h-[76px] pt-1.5 pb-[14px] px-2 grid grid-cols-5 gap-1 bg-white border-t border-ink/10`, plus `env(safe-area-inset-bottom)`. Item: column, icon 22px, label 11px; active = `text-brand-700 font-semibold shadow-[inset_0_2px_0_var(--color-gold-500)]`; inactive `text-ink/65`. Page content gets `pb-24`.

Tabs per role (start page first):

| Role | Tabs |
|---|---|
| مدير / مشرف / معلم | الرئيسية · الحضور · الحلقات · الطلاب · المزيد |
| محاسب | المدفوعات · الطلاب · التقارير · الرسائل · المزيد |
| ولي الأمر | أبنائي · الفواتير · الرسائل · حسابي |
| الطالب | تقدّمي · جدولي · التحديات · حسابي |
| قارئ فقط | التقارير · الحضور · الطلاب · المزيد |

### 4.4 MoreSheet / page المزيد

Profile card (avatar, name, role · track) → grouped lists exactly like the sidebar groups (الأكاديمي، التحفيز، الإداري والمالي، النظام), 52px rows, 32px icon badge `bg-brand-50 text-brand-700`, chevron, count pill for pending items → language row (العربية · English) → logout (`text-danger`). Show only pages the role can access.

### 4.5 StickyActionBar

`sticky bottom-0 px-4 pt-3 pb-5 bg-white border-t border-ink/10 flex gap-2.5`; primary button `h-12 rounded-[10px] bg-brand-700 text-white font-semibold flex-1`; optional 48px square secondary.

### 4.6 Fab

`fixed start-4 bottom-[92px] size-14 rounded-2xl bg-brand-700 text-white shadow-popover` (`bottom-6` when the page has no bottom nav). One per list page, always "create".

### 4.7 Shared atoms

- Card `bg-white border border-ink/10 rounded-card shadow-card p-4`.
- Pill `h-6 px-2 rounded-full text-xs font-semibold` — ok `bg-brand-50 text-brand-700`, warn `bg-gold-500/12 text-gold-700`, err `bg-danger/10 text-danger`, info `bg-info/10 text-info`, neutral `bg-ink/5 text-ink/65`.
- Segmented tabs `bg-ink/5 rounded-[10px] p-[3px] flex gap-[3px]`, item `h-9 rounded-lg text-[13px] font-semibold`, active `bg-white text-brand-700 shadow-card`.
- Filter chips row `flex gap-2 overflow-x-auto no-scrollbar`, chip `h-8 px-3 rounded-full border border-ink/10 bg-white text-[13px]`, active `bg-brand-700 text-white`.
- Search `h-11 rounded-[10px] border border-ink/10 bg-white px-3 flex items-center gap-2`.
- Input `h-12 rounded-md border border-ink/10 bg-white px-3.5 text-[15px]`, label `text-[13px] font-medium mb-1.5`.
- List `bg-white border border-ink/10 rounded-card overflow-hidden divide-y divide-ink/10`, row `min-h-16 px-4 py-2.5 flex items-center gap-3`.
- Avatar 40px circle `bg-brand-50 text-brand-700 text-[13px] font-semibold` with initials.
- Empty state icon 40px + one sentence + one secondary button, centered, `py-6`.
- Skeleton `bg-ink/5 animate-pulse rounded-md`.

## 5. Cross-cutting rules

- One primary button per screen. If a page has a FAB, the FAB is the primary; the sticky bar is the primary otherwise.
- Tables → cards. Any `<table>` visible below `lg` is replaced by a List of rows: title (15/600) + caption (13, muted, ·-separated facts) + trailing pill/amount + chevron. Row taps open the same route the desktop row link opens. Sorting/filtering move into a chips row + a filter bottom sheet.
- Filters persist in the URL (already true on desktop) — the chips read/write the same query params.
- Ornament (`data-ornament`): on mobile the girih pattern appears only in the login header, the dashboard banner, certificates and the student hero — as an 8–12% gold star mark in a corner, never as a full tile behind cards.
- Dates: one line, Gregorian · Hijri, 13px muted. Times with Arabic-Indic digits and ص/م.
- Numbers: `tabular-nums` on every numeric span; phone numbers and REQ-…/receipt refs stay Latin and `dir="ltr"`.
- Loading: skeleton rows in the same shape as the final rows. Saving: 4-second toast at the top under the app bar.
- Language toggle: one row in المزيد (and in الإعدادات); the login page keeps a small EN pill in its header only.

## 6. Page-by-page spec (artboard name → route)

Each entry: layout top→bottom, primary action, what links where.

### 6.1 Login — Login.dc.html → /login

Deep-emerald header (logo tile, app name, Hijri sub-line, EN pill, one Qur'an line in font-quran, corner ornament) → title تسجيل الدخول (Amiri 28) → segmented tabs الموظفون / الطلاب وأولياء الأمور → phone (dir=ltr, inputmode=tel) + password with إظهار → primary دخول (h-12, full width) → footer links: قدّم طلب تسجيل (bold brand), تتبع طلب التسجيل (muted). OTP tab: phone + 6-box code input (already exists) + resend timer.

### 6.2 Register — Register.dc.html → /register

Page header with back + EN pill → 3-step progress bar (4px segments) + "الخطوة ١ من ٣ · بيانات الطالب" → form: name, (CPR | DOB), gender as two toggle buttons, level select, school → sticky التالي: بيانات ولي الأمر. Steps 2 (ولي الأمر) and 3 (الحلقة والباقة + مراجعة) reuse the same frame; last step's primary is إرسال الطلب and it lands on /track?ref=….

### 6.3 Track — Track.dc.html → /track

Search card (request no. or guardian phone, primary بحث) → result card: student name + REQ-… + status pill → vertical timeline (done = emerald dot, current = gold ring, pending = hollow): استلام، اكتمال البيانات، مراجعة المشرف، تحديد الحلقة، السداد → info note (lapis soft) with contact.

### 6.4 Dashboard — Main.dc.html → / (مدير · مشرف · معلم)

MobileAppBar → banner (bg-deep, greeting text-gold-300 Amiri 24, date line, row: track chip · آخر تحديث · 40px refresh with aria-label) → primary تسجيل الحضور (→ /attendance?halaqa=today) → 4 icon tiles: تسجيل سريع، طلبات التسجيل، الحلقات والقاعات، تسجيل دفعة (each 84px, 40px icon circle bg-brand-50) → section حلقة اليوم + الكل: card with name, time · hall · students, pill (رُصد الحضور n/n or gold "لم يُرصد بعد"), 6px progress → section نظرة سريعة: 2-col KPI cards — الطلاب النشطون, الحلقات النشطة, الحضور خلال ٧ أيام (+4px bar), طلبات معلقة (gold icon, hint), المحصَّل هذا الشهر (spans 2, amount + د.ب small, trailing gold pill "١٤ عليهم مستحقات"). Every KPI card is a link with the same filter as desktop. Bottom nav: الرئيسية active. Supervisor sees the same without the money card; accountant's start page is المدفوعات.

### 6.5 More — More.dc.html → /more

See 4.4. Bottom nav: المزيد active.

### 6.6 Halaqat — Halaqat.dc.html → /halaqat

Header with filter icon → segmented الحلقات / القاعات / جدول اليوم → search → chips (الكل n، صباحية، مسائية، بنين، بنات) → cards: name + time pill (today = ok, weekday = neutral, conflict = gold "تعارض قاعة"), teacher · hall · students, 4px attendance bar + % → FAB إضافة حلقة. القاعات tab: hall cards with today's sessions; a conflict opens the hall schedule with the clashing sessions highlighted.

### 6.7 Halaqa detail — HalaqaDetail.dc.html → /halaqat/:id

Header (back, name, ⋯) → breadcrumb → summary card: teacher (link) + status pill, hall (link) · days · time, 3 mini-KPIs (طلاب, حضور ٧ أيام, جلسات الشهر) → segmented الطلاب / الحضور / الحفظ / الجدول → student list (avatar, name, جزء · حضور %, status pill: منتظم / تنبيه غياب / متأخر سداد) → sticky سجّل حضور اليوم + 48px message icon (→ messages with the halaqa's guardians preselected).

### 6.8 Students — Students.dc.html → /students

Header (count in title, sort, filter) → search → chips (الكل، per halaqa، تنبيه غياب، مستحقات) → list rows: avatar, name, halaqa · جزء, attendance % pill (ok ≥ 80, warn < 80) or مستحقات (err), chevron → "n من N" footer + infinite scroll → FAB تسجيل طالب (→ quick register). Bottom nav: الطلاب active.

### 6.9 Student profile — StudentProfile.dc.html → /students/:id

Header (back, title, message icon, ⋯) → breadcrumb → identity card: 52px avatar, full name, halaqa (link) · age · package, status pill, 3 mini-KPIs (حضور ٣٠ يوماً, الجزء, متوسط التقييم) → horizontally scrollable underline tabs (gold 2px): نظرة عامة · ولي الأمر · الحضور · الحفظ · المدفوعات · الشهادات — each tab's "الكل" goes to the parent page with ?student= preset → overview cards: آخر تسميع (3 rows with grade pills), ولي الأمر (name + tel: link, last invoice pill) → two buttons: تسميع جديد (primary) / إصدار شهادة (secondary).

### 6.10 Teachers — Teachers.dc.html → /teachers

Search → cards: 44px avatar, name, halaqat · students, today pill; stats row (حضور طلابه · تسميع الأسبوع · تقييم) → FAB إضافة معلم (→ users/new with role preset).

### 6.11 Attendance — Attendance.dc.html → /attendance

Header (title, history icon) → two selectors side by side: halaqa (dropdown) | date (date picker) → 5 summary tiles (حاضر/متأخر/غائب/معذور/لم يُرصد) live-updating → helper line + الكل حاضر chip → list rows: 36px avatar, name + sub-line, 4 state buttons 44×40 (aria-pressed), selected state uses the matching chart color pair (present emerald-soft, late gold-soft, absent clay-soft, excused lapis-soft) → sticky حفظ الحضور (n من N) → bottom nav الحضور active. Tap-and-hold on a student row opens a bottom sheet for a note/attachment (excuse).

### 6.12 Memorization — Memorization.dc.html → /memorization/new?student=

Header (back, title, history) → student strip card (link) → form: (النوع | السورة), (من آية | إلى آية), verse preview in font-quran on bg-page, 4 grade buttons (ممتاز ٥ / جيد جداً ٤ / جيد ٣ / إعادة ٢), note textarea → sticky حفظ التسميع + 48px "التالي" arrow (save & next student in the halaqa). The list view (/memorization) is the Students list filtered to the halaqa, rows show last grade pill.

### 6.13 Exams — Exams.dc.html → /exams

Segmented القادمة / بانتظار التصحيح / المنتهية → cards with a date block (day number Amiri-free, month) + title + halaqa (link) · registered, countdown pill, two secondary buttons (قائمة الطلاب / تذكير أولياء الأمور); pending-grading card is gold-tinted with a primary إدخال النتائج → FAB اختبار جديد.

### 6.14 Certificates — Certificates.dc.html → /certificates

Live certificate preview (A-landscape ratio, bg-deep, gold inner frame, corner ornament, Amiri title + student name, caption) → 3 secondary buttons (طباعة PDF / إرسال لولي الأمر / تغيير القالب) → list of issued certificates (gold icon badge, title, date · teacher) → sticky إصدار شهادة جديدة (opens student picker sheet).

### 6.15 Lottery — Lottery.dc.html → /lottery

Card: pool select, condition chips (حضور ≥ ٩٠٪ / أتمّ تسميع الأسبوع / بلا شرط), eligible count, primary اسحب القرعة → winner banner (bg-deep, gold name in Amiri, corner ornament, "إبلاغ ولي الأمر" pill button) → previous draws list.

### 6.16 Excellence board — Excellence.dc.html → /excellence

Segmented هذا الشهر / الفصل / العام → halaqa chips → podium: 3 cards (center = first, gold border, 56px avatar, rank badge), points in text-gold-700 → formula caption → ranked list (rank number, name, halaqa, score). Share icon exports an image of the board.

### 6.17 Competitions — Competitions.dc.html → /competitions

Segmented جارية / قادمة / منتهية → cards: title + status pill, meta line, 6px progress + count, avatar stack + leader; registration-open card gets a secondary "تسجيل طلاب حلقتي" → FAB مسابقة جديدة.

### 6.18 Quick register — QuickRegister.dc.html → /register/quick

One-screen form grouped by 12px labels: الطالب (name, CPR | DOB), ولي الأمر (name | phone), الحلقة والباقة (halaqa select showing capacity n/N | package select with price), total row (bg-brand-50) → sticky: secondary حفظ بدون دفع + primary حفظ وتسجيل دفعة (→ payments/new with student preset).

### 6.19 Registration requests — Requests.dc.html → /requests

Chips with counts (معلقة، مقبولة، مرفوضة، الكل) → request cards: avatar, name, age · level · guardian, age-of-request pill; suggested halaqa line (link) on bg-page; actions grid: قبول وتحديد الحلقة (primary) | طلب استكمال | 44px reject (danger icon, confirm dialog). Incomplete requests are clay-tinted with the missing field named.

### 6.20 Packages — Packages.dc.html → /packages

Segmented الباقات / الاشتراكات النشطة / الخصومات → package cards: name + on/off switch, price (22px) + period, subscriber pill, feature chips; recommended card has gold border + pill; inactive card at 75% opacity → FAB باقة جديدة.

### 6.21 Payments & wallets — Payments.dc.html → /payments

Two KPI cards (المحصَّل هذا الشهر with delta; مستحقات متأخرة gold-tinted, links to overdue filter) → segmented آخر العمليات / المتأخرات / المحافظ → transaction rows: student (link), package · method · time, amount (tabular-nums, clay when overdue), status pill (مسدد / جزئي / متأخر) → note "الإيصال يُطبع من صفحة العملية" → sticky تسجيل دفعة. Accountant's bottom nav: المدفوعات active.

### 6.22 Messages — Messages.dc.html → /messages/new

Compose card: recipients as removable tags (prefilled from wherever the user came from: student, halaqa, overdue list), channel chips (رسالة نصية / واتساب / داخل التطبيق), template row (تذكير حضور، تذكير سداد، موعد اختبار، تهنئة إتمام), textarea with {اسم_الطالب} hint and 128/160 counter → recent messages list (title, time · delivered · replies) → sticky إرسال إلى n ولي أمر.

### 6.23 Reports — Reports.dc.html → /reports

Period chips → card "نسبة الحضور اليومية": 7 thin bars (14px, 4px rounded top, chart-present, below-threshold bar chart-late), value above each bar, weekday labels, one-line insight → card "توزيع الحالات": single stacked bar with 2px gaps + legend with labels (never color-only) → list of other reports (حفظ وتسميع، المالي الشهري، أداء المعلمين). Export icon → sheet: PDF / Excel.

### 6.24 Users & permissions — Users.dc.html → /users

Search → role chips → rows: avatar, name, phone (dir=ltr) · last login, role pill (مدير/مشرف = info, معلم = ok, محاسب = warn, قارئ = neutral); suspended users at 70% → note linking to سجل التدقيق → FAB مستخدم جديد.

### 6.25 Settings — Settings.dc.html → /settings

Grouped lists: حسابي (name/phone, password) · العرض (language segmented, ornament كاملة/خفيفة/بلا → data-ornament, numerals, dark mode switch) · التنبيهات (3 switches) · المؤسسة (admin only: بيانات الهيئة، التقويم والعطل، القوالب). Switches 44×26.

### 6.26 Audit log — Audit.dc.html → /audit

Type chips → day headers → event rows: 30px colored icon badge by type (ok/gold/lapis/clay), sentence with actor and entity as links, detail caption, time on the end. Infinite scroll by day.

### 6.27 Parent home — ParentHome.dc.html → /my-children (ولي الأمر)

Greeting line → due-payment banner (gold soft) if any → one card per child: avatar, name, halaqa · teacher, today pill (حضر اليوم / حلقتها الخميس), 3 mini-KPIs, teacher's note of the day, 3 secondary buttons (الحضور / الحفظ / الشهادات or الفواتير). Bottom nav (4): أبنائي · الفواتير · الرسائل · حسابي.

### 6.28 Student progress — StudentProgress.dc.html → /my-progress (الطالب)

Hero (bg-deep): 84px conic progress ring in gold, "أحسنت يا …" Amiri, juz progress and next sura → "هذا الأسبوع" card (attendance pill, last recitations, challenge progress bar) → "إنجازاتي" badge grid (earned = gold-soft, locked = sunken/greyed). Bottom nav (4): تقدّمي · جدولي · التحديات · حسابي.

## 7. Implementation order & acceptance checklist

Order: shell (4.1–4.7) → Dashboard → Attendance → Halaqat + detail → Students + profile → More → Login/Register/Track → Payments → Requests → Quick register → Messages → Reports → Memorization → Exams → Certificates → Teachers → Users → Settings → Audit → Lottery → Excellence → Competitions → Packages → Parent home → Student progress.

Done means, at 390×844 and 360×780:

- [ ] No horizontal scroll on any page; no clipped or ellipsised primary content (names may truncate with title).
- [ ] Exactly one `bg-brand-700` button per screen.
- [ ] Bottom nav visible on root tabs only; inner pages have back + title; breadcrumb present on every inner page.
- [ ] All touch targets ≥ 44px; text ≥ 12px; muted text uses `text-ink/65`, never lighter.
- [ ] Arabic-Indic numerals everywhere except phone/reference numbers; `tabular-nums` on numbers.
- [ ] Every list has a designed empty state and a skeleton state.
- [ ] Status colors always paired with a label (pill text), never color-alone.
- [ ] Keyboard: Tab reaches every control; focus ring visible; `aria-current`, `aria-pressed`, `aria-label` in place.
- [ ] Desktop (lg+) screenshots are pixel-identical to before the change.
- [ ] `npm run lint` and `npm run build` pass; English (`lang="en"`) mobile mirrors correctly with `ltr:` variants.
