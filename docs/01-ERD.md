# Ahl Al-Quran System — Entity Relationship Diagram
# نظام أهل القرآن — مخطط قاعدة البيانات

## Conventions

- Every table: `id` = unsigned big integer; `created_at` / `updated_at` stored in UTC, shown in Asia/Bahrain by API Resources.
- Status / type columns are `string(32)` columns validated in Form Requests and backed by PHP enums. No DB `ENUM`.
- Money columns end in `_fils` and are integers (1 BHD = 1000 fils).
- Files are never stored in the DB. Every file (photo, receipt image, receipt PDF, exam sheet, recitation audio, certificate PDF, logo) is a row in **`media`** (polymorphic, single source of truth) and lives on the configured Filesystem disk (local or S3). The only cached paths are `students.photo_path` / `photo_thumb_path` (denormalised for per-row rendering in lists and rosters). Path columns shown on `certificates`, `exam_attempts`, `exam_answers` and `payments` in the diagrams below were **removed** in the approved revision; those files are `media` rows with collections `certificate`, `exam_sheet`, `recitation`, `receipt_image`, `receipt_pdf`.
- Money out is recorded in **`refunds`** (linked to the `refund`-type wallet transaction); `payments.amount_fils` is always positive.
- `packages` carry `name_ar` / `name_en` beside `name`; `registration_requests` carry `waitlist_position`.
- `students.guardian_phone` is a synced copy of the guardian user's phone; it is changed only on the guardian user and propagated to all children.
- List-like columns (`days`, `options`, `correct_answer`, `question_order`, `variables`, `old_values`) are `text` holding a JSON string, cast with Eloquent `array`. No JSON operators are used in queries, so the same code runs on MySQL, PostgreSQL and SQLite.
- The domain "session" (a class held on a date) is named **`lesson_sessions`**, because Laravel already owns a `sessions` table for the session driver.
- Bilingual: `locale` (`ar` | `en`) on `users`, `students` and `registration_requests`; every message template has `body_ar` and `body_en`. User-entered content (package names, notes) is stored once as entered.

## A. Identity, access and platform

```mermaid
erDiagram
    users ||--o| teachers : "profile"
    users ||--o| students : "student login"
    users ||--o{ students : "guardian login"
    users ||--o{ model_has_roles : ""
    roles ||--o{ model_has_roles : ""
    roles ||--o{ role_has_permissions : ""
    permissions ||--o{ role_has_permissions : ""
    users ||--o{ personal_access_tokens : ""
    users ||--o{ audit_logs : "actor"
    users ||--o{ media : "created_by"

    users {
        bigint id PK
        string name
        string phone UK "E.164 +973..."
        string email "nullable unique"
        string password "nullable for OTP-only users"
        string gender "male/female nullable"
        string locale "ar/en default ar"
        boolean is_active
        datetime phone_verified_at
        datetime last_login_at
    }
    teachers {
        bigint id PK
        bigint user_id FK "unique"
        string gender
        string specialization
        text bio
        boolean is_active
    }
    otp_codes {
        bigint id PK
        string phone "index"
        string code_hash
        string purpose "login"
        tinyint attempts "max 3"
        datetime expires_at "index, 5 min"
        datetime used_at
    }
    settings {
        bigint id PK
        string key UK
        text value
        string group "index"
        string type "string/int/bool/json/file"
    }
    audit_logs {
        bigint id PK
        bigint user_id FK "nullable"
        string action "index"
        string auditable_type "composite index"
        bigint auditable_id
        text old_values "json"
        text new_values "json"
        string ip
        string user_agent
        datetime created_at "index"
    }
    media {
        bigint id PK
        string model_type "composite index"
        bigint model_id
        string collection "photo/photo_thumb/receipt/exam_sheet/recitation/certificate/logo"
        string disk
        string path
        string mime
        bigint size
        string original_name
        bigint created_by FK "nullable"
    }
```

## B. Packages and registration

```mermaid
erDiagram
    packages ||--o{ registration_requests : ""
    students ||--o{ registration_requests : "linked on accept"
    users ||--o{ registration_requests : "decided_by"
    students ||--o{ media : "photo 512 + thumb 96"
    registration_requests ||--o{ media : "photo before accept"

    packages {
        bigint id PK
        string name
        text description
        tinyint min_age
        tinyint max_age
        string gender "male/female/mixed"
        int seats
        int price_fils
        text days "json [sat,mon,wed]"
        time start_time
        time end_time
        date start_date "index"
        date end_date
        string term
        int plan_ayahs "yearly plan size"
        string status "draft/open/closed index"
    }
    registration_requests {
        bigint id PK
        string request_no UK
        bigint package_id FK
        bigint student_id FK "nullable"
        string full_name
        date birth_date
        string gender
        string student_phone "nullable"
        string guardian_name
        string guardian_phone "index"
        string memorization_level
        string locale "ar/en"
        tinyint age_at_start "computed server-side"
        string status "pending/accepted/waitlist/rejected index"
        bigint decided_by FK "nullable"
        datetime decided_at
        text reason
        text notes
    }
    students {
        bigint id PK
        string student_no UK
        bigint user_id FK "nullable student login"
        bigint guardian_user_id FK "nullable guardian login"
        string full_name
        date birth_date "index"
        string gender "index"
        string student_phone "nullable index"
        string guardian_name
        string guardian_phone "index"
        string memorization_level "index"
        string locale "ar/en"
        string photo_path "nullable 512px webp"
        string photo_thumb_path "nullable 96px webp"
        int yearly_target_ayahs "nullable override"
        string status "active/inactive/graduated/suspended index"
        text notes
        datetime deleted_at
    }
```

## C. Lessons, locations, sessions and attendance

```mermaid
erDiagram
    packages ||--o{ lessons : ""
    users ||--o{ lessons : "teacher"
    locations ||--o{ lessons : "default hall"
    lessons ||--o{ lesson_students : ""
    students ||--o{ lesson_students : ""
    lessons ||--o{ lesson_location_overrides : "one day"
    locations ||--o{ lesson_location_overrides : ""
    locations ||--o{ location_bookings : "manual/exam/event"
    lessons ||--o{ lesson_sessions : ""
    locations ||--o{ lesson_sessions : "effective hall"
    lesson_sessions ||--o{ attendances : ""
    students ||--o{ attendances : ""

    locations {
        bigint id PK
        string name
        string code
        text address
        string map_link
        int capacity
        boolean is_active
        text notes
    }
    lessons {
        bigint id PK
        string name
        bigint package_id FK
        bigint teacher_id FK "users"
        bigint location_id FK "nullable"
        text days "json"
        time start_time
        time end_time
        int capacity
        date start_date "index"
        date end_date "nullable"
        string status "active/paused/ended index"
    }
    lesson_students {
        bigint id PK
        bigint lesson_id FK "unique with student"
        bigint student_id FK
        date joined_at
        date left_at
        string status "active/left"
        text current_memorization "latest assignment"
        text current_revision
    }
    lesson_location_overrides {
        bigint id PK
        bigint lesson_id FK "unique with date"
        bigint location_id FK
        date override_date "index"
        text reason
        datetime notified_at
        bigint created_by FK
    }
    location_bookings {
        bigint id PK
        bigint location_id FK
        string title
        string source "manual/exam/event"
        date booking_date "index"
        time start_time
        time end_time
        bigint exam_id FK "nullable"
        bigint created_by FK
    }
    lesson_sessions {
        bigint id PK
        bigint lesson_id FK "unique with date"
        date session_date "index"
        time start_time
        time end_time
        bigint location_id FK "nullable"
        string status "scheduled/held/cancelled index"
        datetime reminder_sent_at
        datetime attendance_taken_at
        bigint taken_by FK "nullable"
        text notes
    }
    attendances {
        bigint id PK
        bigint lesson_session_id FK "unique with student"
        bigint student_id FK
        string status "present/absent/late/excused index"
        text memorization_assignment
        text revision_assignment
        text note
        bigint recorded_by FK
        datetime absence_notified_at
    }
```

## D. Evaluation, progress and certificates

```mermaid
erDiagram
    students ||--o{ evaluations : ""
    lessons ||--o{ evaluations : ""
    lesson_sessions ||--o{ evaluations : "daily nullable"
    students ||--o{ student_progress : ""
    students ||--o{ certificates : ""
    exams ||--o{ certificates : "nullable"
    lessons ||--o{ certificates : "nullable"
    certificates ||--o| media : "pdf"

    evaluations {
        bigint id PK
        bigint student_id FK
        bigint lesson_id FK
        bigint lesson_session_id FK "nullable"
        string type "daily/monthly index"
        date evaluated_on "index"
        string period "YYYY-MM monthly index"
        tinyint memorization "0-10"
        tinyint tajweed "0-10"
        tinyint revision "0-10"
        tinyint behavior "0-10"
        text note
        bigint evaluated_by FK
        datetime sent_to_guardian_at
    }
    student_progress {
        bigint id PK
        bigint student_id FK
        bigint lesson_id FK "nullable"
        string type "memorized/revised index"
        tinyint surah_number "1-114"
        smallint from_ayah
        smallint to_ayah
        smallint ayah_count
        date recorded_on "index"
        bigint recorded_by FK
        text note
    }
    certificates {
        bigint id PK
        string certificate_no UK
        bigint student_id FK
        string type "completion/exam index"
        bigint exam_id FK "nullable"
        bigint lesson_id FK "nullable"
        string title
        date issued_on
        string file_path
        bigint issued_by FK
        datetime sent_at
    }
```

### D2. Quran position and difficulties (added 2026-09-27, section 12)

```mermaid
erDiagram
    students ||--o{ student_issues : ""
    lessons ||--o{ student_issues : "nullable"
    evaluations ||--o{ student_issues : "opened from, nullable"
    student_issues ||--o{ issue_notes : ""
    users ||--o{ issue_notes : "added_by"

    quran_surahs {
        tinyint number PK
        string name_ar
        string name_en
        smallint ayah_count
        tinyint juz_start "index"
    }
    student_issues {
        bigint id PK
        bigint student_id FK
        bigint lesson_id FK "nullable"
        bigint evaluation_id FK "nullable"
        string category "tajweed/weak_memorization/... index"
        string subcategory "tajweed aspect, nullable"
        text description
        text action_plan
        string severity "low/medium/high index"
        string status "open/improving/resolved index"
        bigint opened_by FK
        datetime opened_at
        datetime resolved_at
        date next_follow_up_date
    }
    issue_notes {
        bigint id PK
        bigint student_issue_id FK
        text note
        bigint added_by FK
        date noted_on
    }
```

- `packages.memorization_direction` string(32) `forward` / `backward` (default backward).
- `students.progress_surah`, `progress_ayah`, `progress_juz` (index), `memorized_ayahs`: a cache recomputed from `student_progress` on every ledger change. The ledger stays the source of truth and the position is never typed manually.
- Juz is derived in code (`App\Support\Quran`) from the 30 juz start points; `quran_surahs` is reference data inserted by its migration.

## E. Lottery

```mermaid
erDiagram
    packages ||--o{ lotteries : ""
    lotteries ||--o{ lottery_teachers : ""
    users ||--o{ lottery_teachers : "teacher"
    lessons ||--o{ lottery_teachers : "target circle"
    lotteries ||--o{ lottery_students : "pool"
    students ||--o{ lottery_students : ""
    lotteries ||--o{ lottery_results : ""
    students ||--o{ lottery_results : ""
    users ||--o{ lottery_results : "teacher"

    lotteries {
        bigint id PK
        bigint package_id FK
        string name
        string status "draft/run/approved/cancelled index"
        boolean balance_ages
        boolean keep_siblings
        boolean balance_levels
        string seed
        int run_count
        datetime run_at
        datetime approved_at
        bigint approved_by FK
        bigint created_by FK
    }
    lottery_teachers {
        bigint id PK
        bigint lottery_id FK "unique with teacher"
        bigint teacher_id FK "users"
        bigint lesson_id FK "circle to fill"
        int capacity
    }
    lottery_students {
        bigint id PK
        bigint lottery_id FK "unique with student"
        bigint student_id FK
    }
    lottery_results {
        bigint id PK
        bigint lottery_id FK "unique with student"
        bigint student_id FK
        bigint teacher_id FK
        bigint lesson_id FK
        int run_no
        datetime notified_at
    }
```

## F. Exams

```mermaid
erDiagram
    packages ||--o{ exams : "nullable"
    lessons ||--o{ exams : "nullable"
    exams ||--o{ exam_questions : ""
    exams ||--o{ exam_attempts : ""
    students ||--o{ exam_attempts : ""
    exam_attempts ||--o{ exam_answers : ""
    exam_questions ||--o{ exam_answers : ""
    exam_attempts ||--o| media : "graded sheet image"
    exam_answers ||--o| media : "recitation audio"

    exams {
        bigint id PK
        string name
        bigint package_id FK "nullable"
        bigint lesson_id FK "nullable"
        string type "paper/online index"
        date exam_date "index"
        datetime opens_at "index"
        datetime closes_at
        int duration_minutes
        int total_marks
        int pass_mark
        text syllabus
        boolean randomize
        string status "draft/published/closed/graded index"
        datetime reminder_day_sent_at
        datetime reminder_hour_sent_at
        datetime results_sent_at
        bigint created_by FK
    }
    exam_questions {
        bigint id PK
        bigint exam_id FK
        string type "mcq/true_false/complete_verse/order_verses/recitation"
        text prompt
        text options "json"
        text correct_answer "json"
        int marks
        int sort_order
    }
    exam_attempts {
        bigint id PK
        bigint exam_id FK "unique with student"
        bigint student_id FK
        datetime started_at
        datetime expires_at "server-side timer"
        datetime submitted_at
        string status "in_progress/submitted/graded/expired index"
        text question_order "json"
        int auto_score
        int manual_score
        int total_score
        boolean passed
        string sheet_image_path "paper"
        bigint graded_by FK
        datetime graded_at
    }
    exam_answers {
        bigint id PK
        bigint exam_attempt_id FK "unique with question"
        bigint exam_question_id FK
        text answer "json"
        string audio_path "recitation"
        int score
        boolean is_correct
        bigint graded_by FK
        text grader_note
        datetime saved_at "autosave"
    }
```

## G. Wallet, invoices and payments

```mermaid
erDiagram
    students ||--|| wallets : "one per student"
    wallets ||--o{ wallet_transactions : ""
    students ||--o{ invoices : ""
    packages ||--o{ invoices : "nullable"
    students ||--o{ payments : ""
    payments ||--o{ invoice_payments : "allocation oldest-first"
    invoices ||--o{ invoice_payments : ""
    payments ||--o| wallet_transactions : ""
    invoices ||--o| wallet_transactions : "charge"
    users ||--o{ wallet_transactions : "created_by"
    users ||--o{ payments : "received_by"
    payments ||--o{ media : "receipt image + pdf"

    wallets {
        bigint id PK
        bigint student_id FK "unique"
        bigint balance_fils "signed = SUM(transactions)"
    }
    wallet_transactions {
        bigint id PK
        bigint wallet_id FK
        string type "charge/payment/refund/adjustment index"
        bigint amount_fils "signed delta"
        bigint balance_after_fils
        string reference "index"
        bigint invoice_id FK "nullable"
        bigint payment_id FK "nullable"
        text note "required for adjustment"
        bigint created_by FK
    }
    invoices {
        bigint id PK
        string invoice_no UK
        bigint student_id FK
        bigint package_id FK "nullable"
        string description
        int amount_fils
        int paid_fils
        date due_date "index"
        string status "open/partial/paid/cancelled index"
        string term
        bigint issued_by FK "nullable"
        datetime reminder_before_sent_at
        datetime reminder_after_sent_at
    }
    payments {
        bigint id PK
        string receipt_no UK
        bigint student_id FK
        int amount_fils
        string method "cash/bank_transfer/benefit/card index"
        string reference
        string receipt_image_path
        string receipt_pdf_path
        text note
        bigint received_by FK
        datetime paid_at "index"
        datetime receipt_sent_at
    }
    invoice_payments {
        bigint id PK
        bigint invoice_id FK
        bigint payment_id FK
        int amount_fils
    }
```

## H. Messaging, alerts and background work

```mermaid
erDiagram
    message_templates ||--o{ message_logs : "by key"
    students ||--o{ message_logs : "nullable"
    users ||--o{ message_logs : "nullable"
    users ||--o{ alerts : "resolved_by"

    message_templates {
        bigint id PK
        string key UK "absence otp ..."
        string name_ar
        string name_en
        text body_ar
        text body_en
        text variables "json list"
        boolean is_active
    }
    message_logs {
        bigint id PK
        string recipient_phone "index"
        string recipient_type "student/guardian/user"
        bigint student_id FK "nullable"
        bigint user_id FK "nullable"
        string type "index"
        string template_key
        string locale "ar/en"
        text body
        string status "queued/sent/failed index"
        string provider "openwa/cloud/log"
        string provider_message_id
        tinyint attempts
        text error
        datetime sent_at "index"
    }
    alerts {
        bigint id PK
        string type "location_conflict/registration/repeated_absence/lottery_pending/exam_upcoming/invoice_overdue index"
        string severity "info/warning/danger"
        string title
        text body
        string subject_type "composite index"
        bigint subject_id
        string status "open/resolved index"
        bigint resolved_by FK
        datetime resolved_at
    }
```

Laravel-owned tables (framework migrations, unchanged): `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `personal_access_tokens`, and the five spatie tables `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions`.

## I. Honor board (section 13)

```mermaid
erDiagram
    honor_periods ||--o{ honor_rankings : ""
    students ||--o{ honor_rankings : ""
    lessons ||--o{ honor_rankings : "nullable"
    packages ||--o{ honor_rankings : "nullable"
    lessons ||--o| honor_periods : "circle of the month"
    badges ||--o{ student_badges : ""
    students ||--o{ student_badges : ""
    students ||--o{ honor_points : ""

    honor_periods {
        bigint id PK
        string period "YYYY-MM"
        string gender "male/female, unique with period"
        string status "open/finalized/honored"
        text weights "JSON snapshot"
        bigint circle_of_month_lesson_id FK
        boolean published_to_students
        datetime finalized_at
        datetime honored_at
        bigint honored_by FK
    }
    honor_rankings {
        bigint id PK
        bigint honor_period_id FK "unique with student"
        bigint student_id FK
        bigint lesson_id FK
        bigint package_id FK
        smallint attendance_pct
        smallint evaluation_avg_x100
        int new_ayahs
        int attendance_points_x100
        int evaluation_points_x100
        int memorization_points_x100
        int bonus_points_x100
        int points_x100 "index"
        int rank_in_track
        int rank_in_package
        int rank_in_circle
        int points_change_x100
    }
    badges {
        bigint id PK
        string key UK
        string name_ar
        string name_en
        string rule_type "completed_juz/full_attendance/tajweed_average/most_improved/points_min/competition/challenge/manual"
        int rule_value
        text rule_params "JSON"
        boolean repeatable_monthly
        int bonus_points
        boolean is_active
    }
    student_badges {
        bigint id PK
        bigint student_id FK
        bigint badge_id FK
        string period "YYYY-MM or once; unique with student+badge"
        datetime awarded_at
        string source_type
        bigint source_id
    }
    honor_points {
        bigint id PK
        bigint student_id FK
        string period
        int points_x100
        string source_type "unique with source_id+student"
        bigint source_id
        string reason
    }
```

- Points = attendance 40% + evaluation average 40% + new memorization 20% (weights in settings `honor.weight_*`, snapshotted per period). Rankings are always per gender track; boys and girls never share a period row.
- Excellence certificates reuse `certificates` (type `excellence`, `honor_period_id`).

## J. Competitions and challenges (section 14)

```mermaid
erDiagram
    competitions ||--o{ competition_rounds : ""
    competitions ||--o{ competition_participants : ""
    competitions ||--o{ competition_judges : ""
    competitions ||--o{ competition_prizes : ""
    competition_rounds ||--o{ competition_scores : ""
    competition_participants ||--o{ competition_scores : ""
    users ||--o{ competition_judges : "judge"
    users ||--o{ competition_scores : "judge"
    students ||--o{ competition_participants : ""
    locations ||--o{ competition_rounds : "nullable"
    badges ||--o{ competition_prizes : "nullable"
    challenges ||--o{ challenge_participants : ""
    students ||--o{ challenge_participants : ""
    badges ||--o{ challenges : "reward, nullable"

    competitions {
        bigint id PK
        string name_ar
        string name_en
        string gender "track index"
        string type "memorization/tajweed/recitation/knowledge"
        string scope "circle/package/authority"
        bigint scope_lesson_id FK
        bigint scope_package_id FK
        tinyint min_age
        tinyint max_age
        datetime registration_opens_at
        datetime registration_closes_at
        datetime starts_at
        datetime ends_at
        int max_participants
        string status "draft/open/running/judging/finished/cancelled"
        text criteria "JSON key,name,weight,max"
        string tie_break
        datetime results_published_at
        bigint created_by FK
    }
    competition_rounds {
        bigint id PK
        bigint competition_id FK
        string name
        date round_date
        time start_time
        bigint location_id FK
        smallint sort_order
        text criteria "JSON override"
        string status
        datetime reminder_sent_at
    }
    competition_participants {
        bigint id PK
        bigint competition_id FK "unique with student"
        bigint student_id FK
        datetime registered_at
        bigint registered_by FK
        string status "registered/withdrawn/eliminated/finalist/winner"
        int seed_no
        int final_rank
        int final_score_x100
        datetime rewarded_at "exactly once"
    }
    competition_judges {
        bigint id PK
        bigint competition_id FK
        bigint round_id FK "null = all rounds"
        bigint user_id FK
    }
    competition_scores {
        bigint id PK
        bigint participant_id FK "unique with round+judge"
        bigint round_id FK
        bigint judge_id FK
        text criteria_scores "JSON"
        int total_x100
        text note
    }
    competition_prizes {
        bigint id PK
        bigint competition_id FK "unique with rank"
        smallint rank
        string title
        string certificate_template
        bigint badge_id FK
        int points
    }
    challenges {
        bigint id PK
        string name_ar
        string name_en
        string gender "track"
        string scope
        string goal_type "memorize_range/attendance_days/revision_range/score_streak/points"
        int goal_value
        tinyint surah_number
        smallint from_ayah
        smallint to_ayah
        tinyint min_score
        string score_criterion
        date starts_at
        date ends_at
        bigint reward_badge_id FK
        int reward_points
        string status "draft/active/finished/cancelled"
    }
    challenge_participants {
        bigint id PK
        bigint challenge_id FK "unique with student"
        bigint student_id FK
        datetime joined_at
        bigint joined_by FK
        int progress_value
        tinyint progress_pct
        string status "joined/completed/failed"
        datetime completed_at
        datetime rewarded_at "exactly once"
        datetime nudged_half_at
        datetime nudged_deadline_at
    }
```

- Judges must be staff of the competition's track (checked in Form Requests and Policies). Totals are the weighted criteria (×100) averaged across judges; results stay hidden until `results_published_at`.
- Challenge progress is computed nightly and on every attendance, evaluation and ledger save; `rewarded_at` guards the badge and `honor_points` row so rewards are written once.
- `certificates` gains `competition_id` (type `competition`).
## Key invariants enforced in code (and covered by tests)

1. `wallets.balance_fils` always equals `SUM(wallet_transactions.amount_fils)` for that wallet. Every write goes through `WalletService` inside `DB::transaction()` with `lockForUpdate()`.
2. `invoices.paid_fils` always equals `SUM(invoice_payments.amount_fils)` for that invoice; status derives from `paid_fils` vs `amount_fils`.
3. A student has at most one active `lesson_students` row per package (unique on `lesson_id + student_id`, plus service check).
4. `registration_requests.age_at_start` is computed from `birth_date` and `packages.start_date` server-side; gender and age range are validated in the Form Request.
5. Exam attempts can only be started inside `[opens_at, closes_at]`, and `expires_at = min(started_at + duration, closes_at)`. Answers after `expires_at` are rejected.
6. Photos: originals are discarded; only the 512 px and 96 px WebP variants are stored, and they are served only via 10-minute signed URLs checked by `StudentPolicy::viewPhoto`.
