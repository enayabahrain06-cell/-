<?php

namespace Database\Seeders;

use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Location;
use App\Models\Package;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Lessons\SessionGenerator;
use Illuminate\Database\Seeder;

/**
 * DEMO DATA ONLY (never run in production; DatabaseSeeder does not call it).
 *
 * Two separated tracks (boys and girls) plus one mixed early-years package (ages 4–6).
 * Bahraini Shia names in Arabic, +973 phones with 8 digits. Idempotent (updateOrCreate).
 * Staff password: "password". Students and guardians sign in with a WhatsApp code.
 *
 *  Super Admin              +97336000001  أ. محمد علي المرزوق, both tracks
 *  Supervisor (boys)        +97336000002  أ. حسن جعفر الجمري, male track
 *  Teacher (boys)           +97336000003  الشيخ جعفر آل شهاب
 *  Student (boy)            +97336000004  حسين علي المحروس
 *  Guardian (3 children)    +97336000005  علي حسن المحروس: a boy, a girl and an early-years boy
 *  Supervisor (girls)       +97336000006  أ. فاطمة الشيخ, female track
 *  Teacher (girls)          +97336000007  الأستاذة زينب الموسوي
 *  Teacher (boys, spare)    +97336000008  الأستاذ عباس المرزوق (no circle: a second boys teacher for the lottery)
 *  Teacher (early years)    +97336000009  الأستاذة معصومة الحداد
 *  Guardians (siblings)     +97336000010–12  الستراوي, آل عباس and السماهيجي families
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // Demo data never reaches production: run explicitly with  php artisan db:seed --class=DemoSeeder
        if (app()->environment('production')) {
            $this->command?->error('DemoSeeder refuses to run in production.');

            return;
        }
        $this->call([DatabaseSeeder::class]); // reference data first (idempotent)

        $this->staff('+97336000001', 'أ. محمد علي المرزوق', 'male', 'both', 'super_admin', 'admin@ahlalquran.bh');
        $this->staff('+97336000002', 'أ. حسن جعفر الجمري', 'male', 'male', 'supervisor');
        $this->staff('+97336000006', 'أ. فاطمة الشيخ', 'female', 'female', 'supervisor');
        $maleTeacher = $this->teacher('+97336000003', 'الشيخ جعفر آل شهاب', 'male', 'رواية حفص عن عاصم');
        $femaleTeacher = $this->teacher('+97336000007', 'الأستاذة زينب الموسوي', 'female', 'التجويد والتلاوة');
        $this->teacher('+97336000008', 'الأستاذ عباس المرزوق', 'male', 'الحفظ والمراجعة');
        $earlyTeacher = $this->teacher('+97336000009', 'الأستاذة معصومة الحداد', 'female', 'تعليم الصغار — القاعدة النورانية');

        // Halls: one per track and two shared by schedule.
        $address = 'هيئة التعليم الديني، سار';
        $map = 'https://maps.google.com/?q=26.1886,50.4867';
        $boysHall = Location::updateOrCreate(['code' => 'B01'], ['name' => 'قاعة مأتم سار الكبير', 'gender' => 'male', 'capacity' => 60, 'address' => 'مأتم سار الكبير، سار', 'map_link' => $map, 'is_active' => true]);
        $girlsHall = Location::updateOrCreate(['code' => 'G01'], ['name' => 'قاعة النساء بالهيئة', 'gender' => 'female', 'capacity' => 30, 'address' => $address, 'map_link' => $map, 'is_active' => true]);
        Location::updateOrCreate(['code' => 'S01'], ['name' => 'قاعة الهيئة ٢', 'gender' => 'shared', 'capacity' => 25, 'address' => $address, 'map_link' => $map, 'is_active' => true]);
        $classroom = Location::updateOrCreate(['code' => 'C03'], ['name' => 'الفصل ٣', 'gender' => 'shared', 'capacity' => 15, 'address' => $address, 'map_link' => $map, 'is_active' => true]);

        // Packages: boys and girls kept apart; the only mixed package is early years (4–6).
        $start = now()->startOfMonth();
        $common = ['min_age' => 7, 'max_age' => 14, 'seats' => 30, 'price_fils' => 20000, 'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addMonths(4)->toDateString(), 'term' => '2026-2027 / 1', 'plan_ayahs' => 600, 'status' => 'open', 'memorization_direction' => 'backward'];
        $boys = Package::updateOrCreate(['name' => 'باقة الحفظ — بنين'], $common + ['name_ar' => 'باقة الحفظ — بنين', 'name_en' => 'Memorization — Boys', 'gender' => 'male', 'days' => ['sat', 'mon', 'wed'], 'start_time' => '16:00:00', 'end_time' => '17:30:00']);
        $girls = Package::updateOrCreate(['name' => 'باقة الحفظ — بنات'], $common + ['name_ar' => 'باقة الحفظ — بنات', 'name_en' => 'Memorization — Girls', 'gender' => 'female', 'days' => ['sun', 'tue', 'thu'], 'start_time' => '16:00:00', 'end_time' => '17:30:00']);
        $early = Package::updateOrCreate(['name' => 'باقة البراعم — الطفولة المبكرة'], ['min_age' => 4, 'max_age' => 6, 'seats' => 20, 'price_fils' => 15000, 'plan_ayahs' => 120] + $common + [
            'name_ar' => 'باقة البراعم — الطفولة المبكرة', 'name_en' => 'Early Years (ages 4–6, mixed)', 'gender' => 'mixed', 'days' => ['mon', 'wed'], 'start_time' => '16:00:00', 'end_time' => '17:00:00',
        ]);

        $boysCircle = $this->circle('حلقة الإمام نافع', $boys, $maleTeacher, $boysHall, 15);
        $girlsCircle = $this->circle('حلقة أم المؤمنين خديجة', $girls, $femaleTeacher, $girlsHall, 15);
        $earlyCircle = $this->circle('حلقة البراعم', $early, $earlyTeacher, $classroom, 12);

        // Sibling families, one guardian login each, so "keep siblings together" and the shared guardian view are testable.
        // Children aged 6 or under join the early-years circle; the others their own track. [student_no, name, gender, age]
        $families = [
            ['+97336000005', 'علي حسن المحروس', [['S26DEMO1', 'حسين علي المحروس', 'male', 12], ['S26DEMO2', 'زهراء علي المحروس', 'female', 10], ['S26DEMO3', 'مهدي علي المحروس', 'male', 5]]],
            ['+97336000010', 'جعفر محمد الستراوي', [['S26DEMO4', 'محمد باقر جعفر الستراوي', 'male', 11], ['S26DEMO5', 'سجاد جعفر الستراوي', 'male', 9], ['S26DEMO6', 'زينب جعفر الستراوي', 'female', 8]]],
            ['+97336000011', 'حسن كاظم آل عباس', [['S26DEMO7', 'فاطمة حسن آل عباس', 'female', 10], ['S26DEMO8', 'رقية حسن آل عباس', 'female', 8]]],
            ['+97336000012', 'مهدي رضا السماهيجي', [['S26DEMO9', 'رقية مهدي السماهيجي', 'female', 5], ['S26DEMO10', 'حيدر مهدي السماهيجي', 'male', 6]]],
        ];
        $studentLogin = $this->login('+97336000004', 'حسين علي المحروس', 'male', 'student');
        foreach ($families as [$phone, $guardianName, $children]) {
            $guardian = $this->login($phone, $guardianName, 'male', 'guardian');
            foreach ($children as [$no, $name, $gender, $age]) {
                $student = $this->student($no, $name, $gender, $age, $guardian, $no === 'S26DEMO1' ? $studentLogin : null);
                $circle = $age <= 6 ? $earlyCircle : ($gender === 'male' ? $boysCircle : $girlsCircle);
                LessonStudent::updateOrCreate(['lesson_id' => $circle->id, 'student_id' => $student->id], ['status' => 'active', 'joined_at' => $circle->start_date->toDateString()]);
            }
        }
        foreach ([$boysCircle, $girlsCircle, $earlyCircle] as $circle) {
            app(SessionGenerator::class)->generateFor($circle);
        }

        $this->history($boysCircle, 'male', ['عباس', 'كاظم', 'رضا', 'صادق', 'جواد', 'مرتضى'], $maleTeacher);
        $this->history($girlsCircle, 'female', ['نرجس', 'سكينة', 'معصومة', 'خديجة', 'بتول', 'حوراء'], $femaleTeacher);
        $this->history($earlyCircle, 'mixed', [], $earlyTeacher); // the sibling children above only

        $this->call(EngagementDemoSeeder::class); // honor boards, competitions and challenges (sections 13-14)
    }

    /**
     * A small class per circle plus three weeks of past sessions with attendance, daily scores and
     * ledger entries, so the dashboard, profiles and reports have something to show. Deterministic and idempotent.
     * Each extra student has their own guardian (no siblings), on +973 3611 000N (boys) / 3612 000N (girls).
     */
    private function history(Lesson $circle, string $gender, array $names, User $teacher): void
    {
        $families = $gender === 'male'
            ? ['الدرازي', 'آل سيف', 'جناحي', 'العصفور', 'الجمري', 'الصفار']
            : ['العكري', 'راضي', 'البصري', 'الحداد', 'العلوي', 'الموسوي'];
        $fathers = ['حسن', 'محسن', 'هادي', 'علي', 'كاظم', 'جعفر'];
        $grandfathers = ['محمد', 'حسين', 'عباس', 'مهدي', 'علي', 'حسن'];
        $students = collect($names)->map(function ($first, $i) use ($circle, $gender, $families, $fathers, $grandfathers) {
            $no = sprintf('S26D%s%02d', $gender === 'male' ? 'B' : 'G', $i + 1);
            $guardian = $this->login(sprintf('+973361%s%04d', $gender === 'male' ? '1' : '2', $i + 1), "{$fathers[$i]} {$grandfathers[$i]} {$families[$i]}", 'male', 'guardian');
            $s = $this->student($no, "{$first} {$fathers[$i]} {$families[$i]}", $gender, 8 + $i, $guardian);
            LessonStudent::updateOrCreate(['lesson_id' => $circle->id, 'student_id' => $s->id], ['status' => 'active', 'joined_at' => $circle->start_date->toDateString()]);

            return $s;
        })->push(...Student::whereIn('id', LessonStudent::where('lesson_id', $circle->id)->pluck('student_id'))->where('student_no', 'like', 'S26DEMO%')->get());

        $keys = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
        $days = array_map(fn ($d) => $keys[$d], $circle->days);
        $surah = 114;

        for ($d = today()->subDays(21); $d->lt(today()); $d->addDay()) {
            if (! in_array($d->dayOfWeek, $days, true)) {
                continue;
            }
            $session = \App\Models\LessonSession::updateOrCreate(
                ['lesson_id' => $circle->id, 'session_date' => $d->toDateString()],
                ['start_time' => $circle->start_time, 'end_time' => $circle->end_time, 'location_id' => $circle->location_id, 'status' => 'held', 'attendance_taken_at' => $d->copy()->setTime(17, 30), 'taken_by' => $teacher->id]
            );
            foreach ($students as $s) {
                $roll = crc32($s->student_no.$d->toDateString()) % 20;
                $status = $roll < 14 ? 'present' : ($roll < 16 ? 'late' : ($roll < 19 ? 'absent' : 'excused'));
                \App\Models\Attendance::updateOrCreate(['lesson_session_id' => $session->id, 'student_id' => $s->id], ['status' => $status, 'recorded_by' => $teacher->id]);
                if ($status === 'absent' || $status === 'excused') {
                    continue;
                }
                $base = 6 + ($roll % 4);
                \App\Models\Evaluation::updateOrCreate(
                    ['student_id' => $s->id, 'lesson_session_id' => $session->id, 'type' => 'daily'],
                    ['lesson_id' => $circle->id, 'evaluated_on' => $d->toDateString(), 'evaluated_by' => $teacher->id,
                        'memorization' => min(10, $base + 1), 'tajweed' => $base, 'revision' => min(10, $base + ($roll % 2)), 'behavior' => 9]
                );
            }
            $surah = max(100, $surah - 1);
        }

        // Ledger: each student has memorized from An-Nas backwards a different distance.
        $progress = app(\App\Services\Progress\ProgressService::class);
        foreach ($students->values() as $i => $s) {
            if (\App\Models\StudentProgress::where('student_id', $s->id)->exists()) {
                continue;
            }
            for ($n = 114; $n >= 114 - (4 + $i * 2); $n--) {
                $progress->append($s, ['type' => 'memorized', 'surah_number' => $n, 'from_ayah' => 1, 'to_ayah' => \App\Support\Quran::ayahCount($n), 'lesson_id' => $circle->id, 'recorded_on' => today()->subDays(20 - $i)->toDateString()], $teacher->id);
            }
        }
    }

    private function circle(string $name, Package $package, User $teacher, Location $hall, int $capacity): Lesson
    {
        return Lesson::updateOrCreate(['name' => $name], [
            'package_id' => $package->id, 'teacher_id' => $teacher->id, 'location_id' => $hall->id, 'days' => $package->days,
            'start_time' => $package->start_time, 'end_time' => $package->end_time, 'capacity' => $capacity,
            'start_date' => $package->start_date->toDateString(), 'end_date' => $package->end_date?->toDateString(), 'status' => 'active',
        ]);
    }

    private function staff(string $phone, string $name, string $gender, string $track, string $role, ?string $email = null): User
    {
        $user = User::updateOrCreate(['phone' => $phone], [
            'name' => $name, 'email' => $email, 'password' => 'password', 'gender' => $gender, 'track' => $track,
            'locale' => 'ar', 'is_active' => true, 'phone_verified_at' => now(),
        ]);
        $user->syncRoles([$role]);

        return $user;
    }

    private function teacher(string $phone, string $name, string $gender, string $specialization): User
    {
        $user = $this->staff($phone, $name, $gender, $gender, 'teacher');
        Teacher::updateOrCreate(['user_id' => $user->id], ['gender' => $gender, 'specialization' => $specialization, 'is_active' => true]);

        return $user;
    }

    /** Students and guardians: no password, they sign in with a WhatsApp code. */
    private function login(string $phone, string $name, string $gender, string $role): User
    {
        $user = User::updateOrCreate(['phone' => $phone], [
            'name' => $name, 'password' => null, 'gender' => $gender, 'locale' => 'ar', 'is_active' => true, 'phone_verified_at' => now(),
        ]);
        $user->syncRoles([$role]);

        return $user;
    }

    private function student(string $no, string $name, string $gender, int $age, User $guardian, ?User $login = null): Student
    {
        $student = Student::withTrashed()->updateOrCreate(['student_no' => $no], [
            'user_id' => $login?->id,
            'guardian_user_id' => $guardian->id,
            'full_name' => $name,
            'birth_date' => now()->subYears($age)->toDateString(),
            'gender' => $gender,
            'student_phone' => $login?->phone,
            'guardian_name' => $guardian->name,
            'guardian_phone' => $guardian->phone,
            'memorization_level' => $age <= 6 ? 'none' : 'juz_amma',
            'locale' => 'ar',
            'status' => 'active',
        ]);
        Wallet::firstOrCreate(['student_id' => $student->id], ['balance_fils' => 0]);

        return $student;
    }
}
