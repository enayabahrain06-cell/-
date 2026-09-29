<?php

namespace Database\Seeders;

use App\Enums\AttendanceStatus;
use App\Enums\ExamStatus;
use App\Enums\PaymentMethod;
use App\Models\Attendance;
use App\Models\AttendanceConfirmation;
use App\Models\AttendanceExcuse;
use App\Models\Exam;
use App\Models\InboundMessage;
use App\Models\Invoice;
use App\Models\Lesson;
use App\Models\LessonLocationOverride;
use App\Models\LessonMessagingRule;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\Location;
use App\Models\LocationBooking;
use App\Models\Lottery;
use App\Models\Package;
use App\Models\PhoneStatus;
use App\Models\RegistrationRequest;
use App\Models\Student;
use App\Models\StudentIssue;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\RepeatedAbsenceDetector;
use App\Services\Certificates\ExamCertificateIssuer;
use App\Services\Exams\ExamService;
use App\Services\Issues\IssueService;
use App\Services\Lessons\SessionGenerator;
use App\Services\Lottery\LotteryService;
use App\Services\Registration\AcceptRegistrationAction;
use App\Services\Registration\RegistrationService;
use App\Services\Wallet\WalletService;
use Ahl\Certificates\CertificateService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Demo only (called from DemoSeeder; same local/testing + log-only rule): the day-to-day operations
 * on top of the circles, so every staff page has rows. Fees and payments with one refund, public
 * registration requests in every status, a lottery waiting for approval, online and paper exams with
 * results, exam certificates, student issues, the WhatsApp inbox with excuses, hall bookings and alerts.
 * Each section is skipped when its data already exists, so the seeder stays idempotent.
 *
 *  Guardians of registration requests  +973 3613 000N
 *  Second boys circle (lottery)         حلقة الإمام عاصم, teacher +97336000008, hall S01
 */
class OperationsDemoSeeder extends Seeder
{
    private User $admin;

    private User $boysSupervisor;

    private User $girlsSupervisor;

    public function run(): void
    {
        if ($reason = DemoSeeder::refusal()) {
            $this->command?->error("OperationsDemoSeeder refused: {$reason}");

            return;
        }
        $this->admin = User::where('phone', '+97336000001')->firstOrFail();
        $this->boysSupervisor = User::where('phone', '+97336000002')->firstOrFail();
        $this->girlsSupervisor = User::where('phone', '+97336000006')->firstOrFail();
        auth()->setUser($this->admin); // audit logs and "received by" name the Super Admin

        $this->finance();
        $this->registrations();
        $this->lottery();
        $this->exams();
        $this->issues();
        $this->inbox();
        $this->bookings();
        $this->alerts();
    }

    /** One term invoice per demo student: most paid in full, some partly, a few overdue, one overpaid and refunded. */
    private function finance(): void
    {
        $wallets = app(WalletService::class);
        $methods = [PaymentMethod::Benefit, PaymentMethod::Cash, PaymentMethod::BankTransfer, PaymentMethod::Card];
        $students = Student::where('student_no', 'like', 'S26D%')->orderBy('student_no')->get();

        foreach ($students->values() as $i => $s) {
            if (Invoice::where('student_id', $s->id)->exists()) {
                continue;
            }
            $package = Lesson::whereIn('id', LessonStudent::where('student_id', $s->id)->where('status', 'active')->pluck('lesson_id'))->first()?->package;
            if (! $package) {
                continue;
            }
            $due = $package->start_date->copy()->addDays(14);
            $wallets->createInvoice($s, $package, $package->price_fils, $due, "رسوم {$package->name}", $package->term, $this->admin->id);

            $method = $methods[$i % count($methods)];
            $paidAt = $package->start_date->copy()->addDays(2 + $i % 10)->setTime(17, 0);
            $opts = ['paid_at' => $paidAt, 'received_by' => $this->admin->id, 'notify' => false, 'reference' => $method === PaymentMethod::Cash ? null : 'TRX'.(482100 + $i)];
            match ($i % 6) {
                0 => null, // unpaid: overdue after the due date
                1 => $wallets->recordPayment($s, intdiv($package->price_fils, 2), $method, $opts + ['note' => 'دفعة أولى']),
                default => $wallets->recordPayment($s, $package->price_fils, $method, $opts),
            };
        }

        // Paid twice by mistake: the extra credit is partly refunded in cash.
        $s = $students->first(fn ($s) => $s->student_no === 'S26DEMO4');
        if ($s && $s->refunds()->doesntExist()) {
            $wallets->recordPayment($s, 5000, PaymentMethod::Benefit, ['reference' => 'TRX482999', 'note' => 'دفعة مكررة من ولي الأمر', 'received_by' => $this->admin->id, 'notify' => false]);
            $wallets->refund($s, 5000, PaymentMethod::Cash, 'إرجاع الدفعة المكررة لولي الأمر', null, $this->admin->id);
        }
    }

    /** Public requests in every status. The four "lottery" boys (two brothers) wait for the lottery below. */
    private function registrations(): void
    {
        if (RegistrationRequest::where('guardian_phone', 'like', '+97336130%')->exists()) {
            return;
        }
        $registrations = app(RegistrationService::class);
        $accept = app(AcceptRegistrationAction::class);
        $boys = Package::where('name', 'باقة الحفظ — بنين')->firstOrFail();
        $girls = Package::where('name', 'باقة الحفظ — بنات')->firstOrFail();
        $early = Package::where('name', 'باقة البراعم — الطفولة المبكرة')->firstOrFail();
        $girlsCircle = Lesson::where('name', 'حلقة أم المؤمنين خديجة')->firstOrFail();

        // [package, name, gender, age, guardian, guardian phone, level, outcome, note]
        $rows = [
            [$boys, 'علي رضا الشهابي', 'male', 9, 'رضا عيسى الشهابي', '+97336130001', 'juz_amma', 'pending', null],
            [$boys, 'أحمد جعفر المتروك', 'male', 11, 'جعفر أحمد المتروك', '+97336130002', 'juz_tabarak', 'pending', 'يفضّل حلقة قريبة من سار.'],
            [$girls, 'مريم حسين القصاب', 'female', 9, 'حسين علي القصاب', '+97336130003', 'juz_amma', 'pending', null],
            [$early, 'علي أكبر محمد السندي', 'male', 5, 'محمد حسن السندي', '+97336130004', 'none', 'pending', null],
            [$girls, 'فاطمة الزهراء عيسى البقالي', 'female', 10, 'عيسى جاسم البقالي', '+97336130005', 'juz_amma', 'enrolled', null],
            [$girls, 'حوراء محمد العريبي', 'female', 13, 'محمد صالح العريبي', '+97336130006', 'five_ajza', 'rejected', null],
            [$boys, 'باقر صادق الحايكي', 'male', 7, 'صادق باقر الحايكي', '+97336130009', 'none', 'waitlist', 'ينتظر مقعدًا في الفترة المسائية.'],
            [$boys, 'محمد رضا حسن الخباز', 'male', 8, 'حسن محمد الخباز', '+97336130007', 'juz_amma', 'lottery', null],
            [$boys, 'حسن علي حسن الخباز', 'male', 10, 'حسن محمد الخباز', '+97336130007', 'juz_amma', 'lottery', null],
            [$boys, 'قاسم عيسى المرهون', 'male', 12, 'عيسى قاسم المرهون', '+97336130008', 'juz_tabarak', 'lottery', null],
            [$boys, 'زين العابدين أحمد القفاص', 'male', 9, 'أحمد يوسف القفاص', '+97336130010', 'juz_amma', 'lottery', null],
        ];
        foreach ($rows as [$package, $name, $gender, $age, $guardian, $phone, $level, $outcome, $note]) {
            $request = $registrations->submit($package, [
                'full_name' => $name, 'birth_date' => $package->start_date->copy()->subYears($age)->subMonths(3)->toDateString(), 'gender' => $gender,
                'guardian_name' => $guardian, 'guardian_phone' => $phone, 'memorization_level' => $level, 'locale' => 'ar', 'notes' => $note,
            ]);
            $by = $gender === 'female' ? $this->girlsSupervisor->id : $this->boysSupervisor->id;
            match ($outcome) {
                'enrolled' => $accept->execute($request, $by, lessonId: $girlsCircle->id),
                'rejected' => $registrations->reject($request, 'الطالبة مسجّلة في حلقة أخرى في الفترة نفسها.', $by),
                'waitlist' => $registrations->moveToWaitlist($request, $by),
                'lottery' => $accept->execute($request, $by, lottery: true),
                default => null,
            };
        }
    }

    /** A second boys circle for the spare teacher, and a lottery over the four waiting boys, run but not yet approved. */
    private function lottery(): void
    {
        $boys = Package::where('name', 'باقة الحفظ — بنين')->firstOrFail();
        if (Lottery::where('name', 'قرعة باقة الحفظ — بنين (الدفعة الثانية)')->exists()) {
            return;
        }
        $spare = User::where('phone', '+97336000008')->firstOrFail();
        $hall = Location::where('code', 'S01')->firstOrFail();
        $circle = Lesson::updateOrCreate(['name' => 'حلقة الإمام عاصم'], [
            'package_id' => $boys->id, 'teacher_id' => $spare->id, 'location_id' => $hall->id, 'days' => $boys->days,
            'start_time' => $boys->start_time, 'end_time' => $boys->end_time, 'capacity' => 12,
            'start_date' => $boys->start_date->toDateString(), 'end_date' => $boys->end_date?->toDateString(), 'status' => 'active',
        ]);
        app(SessionGenerator::class)->generateFor($circle);
        $first = Lesson::where('name', 'حلقة الإمام نافع')->firstOrFail();

        $lotteries = app(LotteryService::class);
        $lottery = $lotteries->create([
            'package_id' => $boys->id, 'name' => 'قرعة باقة الحفظ — بنين (الدفعة الثانية)', 'keep_siblings' => true, 'balance_ages' => true,
            'teachers' => [
                ['teacher_id' => $first->teacher_id, 'lesson_id' => $first->id, 'capacity' => 2],
                ['teacher_id' => $spare->id, 'lesson_id' => $circle->id, 'capacity' => 8],
            ],
        ], $this->boysSupervisor);
        $lotteries->run($lottery, 'demo2026');
        // A cancelled earlier attempt, so the list shows more than one status.
        $old = $lotteries->create(['package_id' => $boys->id, 'name' => 'قرعة تجريبية (ملغاة)', 'teachers' => [['teacher_id' => $spare->id, 'lesson_id' => $circle->id, 'capacity' => 4]]], $this->boysSupervisor);
        $lotteries->cancel($old);
    }

    /**
     * Boys: last week's online exam, taken and graded, with exam certificates (some approved, some awaiting approval).
     * Girls: a graded paper exam in a booked hall, and an online exam opening next week. Plus a draft final exam.
     */
    private function exams(): void
    {
        if (Exam::where('name', 'اختبار جزء عمّ الشهري')->exists()) {
            return;
        }
        $exams = app(ExamService::class);
        $boysCircle = Lesson::where('name', 'حلقة الإمام نافع')->firstOrFail();
        $girlsCircle = Lesson::where('name', 'حلقة أم المؤمنين خديجة')->firstOrFail();
        $maleTeacher = User::findOrFail($boysCircle->teacher_id);
        $femaleTeacher = User::findOrFail($girlsCircle->teacher_id);

        $opens = today()->subDays(7)->setTime(16, 0);
        $online = Exam::create([
            'name' => 'اختبار جزء عمّ الشهري', 'lesson_id' => $boysCircle->id, 'package_id' => $boysCircle->package_id, 'type' => 'online',
            'exam_date' => $opens->toDateString(), 'opens_at' => $opens, 'closes_at' => $opens->copy()->addHours(4), 'duration_minutes' => 30,
            'total_marks' => 20, 'pass_mark' => 12, 'syllabus' => 'من سورة الناس إلى سورة الضحى، مع أحكام النون الساكنة والتنوين.', 'randomize' => true,
            'status' => 'draft', 'created_by' => $maleTeacher->id,
        ]);
        $q = fn (int $n, string $type, string $prompt, ?array $options, ?array $correct) => $online->questions()->create(['type' => $type, 'prompt' => $prompt, 'options' => $options, 'correct_answer' => $correct, 'marks' => 4, 'sort_order' => $n]);
        $mcq = $q(1, 'mcq', 'كم عدد آيات سورة الإخلاص؟', [['key' => 'a', 'text' => 'ثلاث آيات'], ['key' => 'b', 'text' => 'أربع آيات'], ['key' => 'c', 'text' => 'خمس آيات']], ['key' => 'b']);
        $tf = $q(2, 'true_false', 'سورة الناس هي آخر سورة في المصحف الشريف.', null, ['value' => true]);
        $verse = $q(3, 'complete_verse', 'أكمل الآية: ﴿قُلْ أَعُوذُ بِرَبِّ ...﴾', null, ['text' => 'الفلق', 'alternatives' => ['الْفَلَقِ']]);
        $order = $q(4, 'order_verses', 'رتّب آيات سورة الكوثر.', [['key' => '1', 'text' => 'إِنَّا أَعْطَيْنَاكَ الْكَوْثَرَ'], ['key' => '2', 'text' => 'فَصَلِّ لِرَبِّكَ وَانْحَرْ'], ['key' => '3', 'text' => 'إِنَّ شَانِئَكَ هُوَ الْأَبْتَرُ']], ['order' => ['1', '2', '3']]);
        $q(5, 'recitation', 'اتلُ سورة العصر مع مراعاة أحكام التجويد.', null, null);
        $exams->publish($online);

        foreach ($exams->eligibleStudents($online)->values() as $i => $s) {
            $roll = crc32($s->student_no.'exam');
            $start = $opens->copy()->addMinutes(10 + $i * 7);
            $attempt = $exams->startAttempt($online, $s, $start);
            $exams->saveAnswers($attempt, [
                ['question_id' => $mcq->id, 'answer' => ['key' => $roll % 4 ? 'b' : 'c']],
                ['question_id' => $tf->id, 'answer' => ['value' => $roll % 5 !== 0]],
                ['question_id' => $verse->id, 'answer' => ['text' => $roll % 3 ? 'الفلق' : 'الناس']],
                ['question_id' => $order->id, 'answer' => ['order' => $roll % 2 ? ['1', '2', '3'] : ['2', '1', '3']]],
            ], $start->copy()->addMinutes(15));
            $attempt = $exams->submit($attempt, $start->copy()->addMinutes(22));
            $recitation = $attempt->answers()->whereHas('question', fn ($qq) => $qq->where('type', 'recitation'))->first();
            $exams->gradeManually($attempt, [['answer_id' => $recitation->id, 'score' => 2 + $roll % 3, 'grader_note' => $roll % 3 ? 'تلاوة جيدة' : 'يحتاج مراجعة المدود']], $maleTeacher);
        }
        $exams->close($online);
        $drafts = app(ExamCertificateIssuer::class)->issue($online, $maleTeacher);
        $drafts->take(3)->each(fn ($c) => app(CertificateService::class)->approve($c, $this->boysSupervisor));

        // Girls: paper exam graded by the teacher, held after the circle in the girls' hall.
        $paperDate = today()->subDays(4);
        $paper = Exam::create([
            'name' => 'اختبار التجويد التحريري', 'lesson_id' => $girlsCircle->id, 'package_id' => $girlsCircle->package_id, 'type' => 'paper',
            'exam_date' => $paperDate->toDateString(), 'opens_at' => $paperDate->copy()->setTime(17, 45), 'closes_at' => $paperDate->copy()->setTime(18, 45),
            'duration_minutes' => 45, 'total_marks' => 20, 'pass_mark' => 12, 'syllabus' => 'أحكام الميم الساكنة، المدود، القلقلة.', 'randomize' => false,
            'status' => 'draft', 'created_by' => $femaleTeacher->id,
        ]);
        $exams->publish($paper);
        $exams->recordPaperScores($paper, $exams->eligibleStudents($paper)->values()->map(fn ($s, $i) => ['student_id' => $s->id, 'score' => 10 + (crc32($s->student_no) % 11)])->all(), $femaleTeacher);
        $exams->close($paper);
        LocationBooking::create(['location_id' => $girlsCircle->location_id, 'title' => $paper->name, 'source' => 'exam', 'exam_id' => $paper->id,
            'booking_date' => $paperDate->toDateString(), 'start_time' => '17:45', 'end_time' => '18:45', 'created_by' => $femaleTeacher->id]);

        // Girls: online exam opening next week (upcoming on the dashboard and on the students' My exams page).
        $next = today()->addDays(6)->setTime(18, 0);
        $upcoming = Exam::create([
            'name' => 'اختبار سورة النبأ', 'lesson_id' => $girlsCircle->id, 'package_id' => $girlsCircle->package_id, 'type' => 'online',
            'exam_date' => $next->toDateString(), 'opens_at' => $next, 'closes_at' => $next->copy()->addHours(3), 'duration_minutes' => 20,
            'total_marks' => 10, 'pass_mark' => 6, 'syllabus' => 'سورة النبأ كاملة.', 'randomize' => true, 'status' => 'draft', 'created_by' => $femaleTeacher->id,
        ]);
        $upcoming->questions()->create(['type' => 'mcq', 'prompt' => 'كم عدد آيات سورة النبأ؟', 'options' => [['key' => 'a', 'text' => '٣٠'], ['key' => 'b', 'text' => '٤٠'], ['key' => 'c', 'text' => '٤٥']], 'correct_answer' => ['key' => 'b'], 'marks' => 5, 'sort_order' => 1]);
        $upcoming->questions()->create(['type' => 'complete_verse', 'prompt' => 'أكمل: ﴿عَمَّ يَتَسَاءَلُونَ عَنِ النَّبَإِ ...﴾', 'correct_answer' => ['text' => 'العظيم', 'alternatives' => ['الْعَظِيمِ']], 'marks' => 5, 'sort_order' => 2]);
        $exams->publish($upcoming);

        Exam::create([
            'name' => 'الاختبار النهائي للفصل الأول', 'package_id' => $boysCircle->package_id, 'type' => 'paper',
            'exam_date' => today()->addDays(40)->toDateString(), 'opens_at' => today()->addDays(40)->setTime(16, 0), 'closes_at' => today()->addDays(40)->setTime(18, 0),
            'duration_minutes' => 60, 'total_marks' => 50, 'pass_mark' => 30, 'syllabus' => 'جزء عمّ كاملًا حفظًا وتجويدًا.', 'status' => ExamStatus::Draft, 'created_by' => $this->boysSupervisor->id,
        ]);

        // A manual excellence certificate still waiting for approval, and an approved attendance certificate.
        $certificates = app(CertificateService::class);
        $boy = Student::where('student_no', 'S26DEMO1')->first();
        $girl = Student::where('student_no', 'S26DEMO7')->first();
        if ($boy) {
            $certificates->createDraft($boy, 'excellence', ['achievement' => 'التميّز في الحفظ والتجويد', 'grade' => 'excellent', 'context' => $boysCircle, 'source' => 'manual'], $maleTeacher);
        }
        if ($girl) {
            $certificates->approve($certificates->createDraft($girl, 'attendance', ['achievement' => 'الانتظام في الحضور', 'context' => $girlsCircle, 'source' => 'manual'], $femaleTeacher), $this->girlsSupervisor);
        }
    }

    /** Follow-up cases across categories and statuses, each with notes. */
    private function issues(): void
    {
        if (StudentIssue::where('description', 'يقصّر المدّ المتصل والمنفصل في التلاوة.')->exists()) {
            return;
        }
        $service = app(IssueService::class);
        // [student_no, category, subcategory, severity, description, plan, notes [[days ago, note, status?]]]
        $rows = [
            ['S26DB01', 'tajweed', 'madd', 'medium', 'يقصّر المدّ المتصل والمنفصل في التلاوة.', 'تمرين يومي على آيات المدّ مع التسجيل الصوتي.', [[10, 'بدأ التمرين على سورة النبأ.'], [3, 'تحسّن ملحوظ في المدّ المتصل.', 'improving']]],
            ['S26DEMO5', 'weak_revision', null, 'high', 'ينسى السور التي حفظها قبل شهر عند المراجعة.', 'مراجعة سورتين يوميًا في البيت بمتابعة ولي الأمر.', [[8, 'تواصلنا مع ولي الأمر واتفقنا على جدول مراجعة.']]],
            ['S26DG03', 'concentration', null, 'low', 'تتشتت في آخر الحلقة.', 'جلوس في الصف الأول وتقسيم الحفظ إلى مقاطع قصيرة.', [[14, 'تم تغيير مكان الجلوس.'], [5, 'الانتباه أفضل، أُغلقت الحالة.', 'resolved']]],
            ['S26DEMO9', 'reading_fluency', null, 'medium', 'تتعثر في قراءة الكلمات الطويلة من المصحف.', 'القاعدة النورانية: درس الحروف المتصلة.', []],
            ['S26DB04', 'attendance', null, 'high', 'غياب متكرر في أيام الاثنين.', 'اتصال بولي الأمر لمعرفة السبب.', [[2, 'ولي الأمر أفاد بتعارض مع درس آخر، ننتظر الحل.']]],
        ];
        foreach ($rows as [$no, $category, $sub, $severity, $description, $plan, $notes]) {
            $student = Student::where('student_no', $no)->first();
            if (! $student) {
                continue;
            }
            $teacher = User::find(Lesson::whereIn('id', LessonStudent::where('student_id', $student->id)->pluck('lesson_id'))->value('teacher_id')) ?? $this->admin;
            $issue = $service->create($student, ['category' => $category, 'subcategory' => $sub, 'severity' => $severity, 'description' => $description, 'action_plan' => $plan, 'next_follow_up_date' => today()->addDays(5)->toDateString()], $teacher);
            $issue->update(['opened_at' => now()->subDays(16)]);
            foreach ($notes as $note) {
                $service->addNote($issue, ['note' => $note[1], 'noted_on' => today()->subDays($note[0])->toDateString()] + (isset($note[2]) ? ['status' => $note[2]] : []), $teacher);
            }
        }
    }

    /**
     * WhatsApp inbox: guardians confirming attendance, an excuse applied automatically, one awaiting review,
     * a free-text question left open, one number that stopped messages, one invalid number, and a circle with the
     * second reminder turned off.
     */
    private function inbox(): void
    {
        if (InboundMessage::where('body', 'نعتذر عن غياب اليوم بسبب سفر العائلة')->exists()) {
            return;
        }
        $girlsCircle = Lesson::where('name', 'حلقة أم المؤمنين خديجة')->firstOrFail();
        $boysCircle = Lesson::where('name', 'حلقة الإمام نافع')->firstOrFail();
        $last = fn (Lesson $l) => LessonSession::where('lesson_id', $l->id)->where('status', 'held')->orderByDesc('session_date')->first();
        $in = fn (Student $s, string $body, string $intent, LessonSession $session, string $status, int $hoursBefore) => InboundMessage::create([
            'from_phone' => $s->guardian_phone, 'body' => $body, 'provider' => 'log', 'intent' => $intent, 'user_id' => $s->guardian_user_id, 'student_id' => $s->id,
            'lesson_session_id' => $session->id, 'status' => $status, 'received_at' => $session->session_date->copy()->setTime(16, 0)->subHours($hoursBefore),
        ]);

        foreach ([$girlsCircle, $boysCircle] as $circle) {
            $session = $last($circle);
            if (! $session) {
                continue;
            }
            $rows = Attendance::where('lesson_session_id', $session->id)->with('student')->get();
            foreach ($rows->where('status', AttendanceStatus::Present)->take(3) as $a) {
                $msg = $in($a->student, 'تم', 'confirm', $session, 'processed', 3);
                AttendanceConfirmation::firstOrCreate(['lesson_session_id' => $session->id, 'student_id' => $a->student_id], ['confirmed_at' => $msg->received_at, 'via' => 'whatsapp', 'phone' => $msg->from_phone]);
            }
            foreach ($rows->where('status', AttendanceStatus::Excused)->take(1) as $a) {
                $msg = $in($a->student, 'عذر، الطالب مريض اليوم', 'excuse', $session, 'processed', 2);
                AttendanceExcuse::create(['lesson_session_id' => $session->id, 'student_id' => $a->student_id, 'attendance_id' => $a->id, 'inbound_message_id' => $msg->id,
                    'phone' => $msg->from_phone, 'body' => $msg->body, 'source' => 'whatsapp', 'status' => 'applied']);
            }
            // Sent after attendance was taken: waits for a supervisor. One of them was already approved.
            foreach ($rows->where('status', AttendanceStatus::Absent)->take(2)->values() as $k => $a) {
                $msg = $in($a->student, 'نعتذر عن غياب اليوم بسبب سفر العائلة', 'excuse', $session, 'processed', -3);
                $excuse = AttendanceExcuse::create(['lesson_session_id' => $session->id, 'student_id' => $a->student_id, 'attendance_id' => $a->id, 'inbound_message_id' => $msg->id,
                    'phone' => $msg->from_phone, 'body' => $msg->body, 'source' => 'whatsapp', 'status' => 'pending']);
                if ($k === 1 && $circle->is($boysCircle)) {
                    app(AttendanceService::class)->approveExcuse($excuse, $this->boysSupervisor);
                }
            }
        }

        $girl = Student::where('student_no', 'S26DEMO7')->first();
        $boy = Student::where('student_no', 'S26DB02')->first();
        if ($girl && ($session = $last($girlsCircle))) {
            $in($girl, 'السلام عليكم، هل الحلقة قائمة يوم الخميس القادم؟', 'other', $session, 'open', 20);
        }
        if ($boy && ($session = $last($boysCircle))) {
            $in($boy, 'إيقاف', 'stop', $session, 'processed', 30);
            PhoneStatus::updateOrCreate(['phone' => $boy->guardian_phone], ['opted_out_at' => now()->subDays(2)]);
        }
        PhoneStatus::updateOrCreate(['phone' => '+97336120006'], ['failed_count' => 3, 'invalid_at' => now()->subDay(), 'last_error' => 'Number is not on WhatsApp']);
        LessonMessagingRule::updateOrCreate(['lesson_id' => Lesson::where('name', 'حلقة البراعم')->value('id')], ['reminders_enabled' => true, 'second_reminder_enabled' => false]);
    }

    /** Hall calendar: a guardians' meeting, a Quran evening, and the boys' circle moved to another hall for one day. */
    private function bookings(): void
    {
        if (LocationBooking::where('title', 'لقاء أولياء الأمور')->exists()) {
            return;
        }
        $shared = Location::where('code', 'S01')->firstOrFail();
        $boysHall = Location::where('code', 'B01')->firstOrFail();
        LocationBooking::create(['location_id' => $shared->id, 'title' => 'لقاء أولياء الأمور', 'source' => 'event', 'booking_date' => today()->addDays(3)->toDateString(), 'start_time' => '19:00', 'end_time' => '20:30', 'created_by' => $this->admin->id]);
        LocationBooking::create(['location_id' => $boysHall->id, 'title' => 'أمسية قرآنية', 'source' => 'event', 'booking_date' => today()->addDays(9)->toDateString(), 'start_time' => '19:30', 'end_time' => '21:00', 'created_by' => $this->admin->id]);
        LocationBooking::create(['location_id' => $shared->id, 'title' => 'صيانة التكييف', 'source' => 'manual', 'booking_date' => today()->addDays(12)->toDateString(), 'start_time' => '09:00', 'end_time' => '12:00', 'created_by' => $this->admin->id]);

        $boysCircle = Lesson::where('name', 'حلقة الإمام نافع')->firstOrFail();
        $next = LessonSession::where('lesson_id', $boysCircle->id)->where('session_date', '>', today()->addDays(9)->toDateString())->orderBy('session_date')->first();
        if ($next) {
            LessonLocationOverride::updateOrCreate(['lesson_id' => $boysCircle->id, 'override_date' => $next->session_date->toDateString()],
                ['location_id' => Location::where('code', 'C03')->value('id'), 'reason' => 'القاعة الكبرى محجوزة لأمسية قرآنية.', 'created_by' => $this->admin->id]);
        }
    }

    /** Alerts the scheduler would raise: repeated absences and overdue invoices. */
    private function alerts(): void
    {
        $detector = app(RepeatedAbsenceDetector::class);
        Student::where('status', 'active')->pluck('id')->each(fn ($id) => $detector->check($id));
        Artisan::call('invoices:send-reminders');
    }
}
