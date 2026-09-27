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
 * Demo data for two fully separated tracks (boys and girls). Idempotent (updateOrCreate).
 * Staff password: "password". Students and guardians sign in with a WhatsApp code.
 *
 *  Super Admin              +97336000001  both tracks
 *  Supervisor (boys)        +97336000002  male track
 *  Teacher (boys)           +97336000003
 *  Student (boy)            +97336000004
 *  Guardian (both children) +97336000005
 *  Supervisor (girls)       +97336000006  أ. فاطمة الشيخ, female track
 *  Teacher (girls)          +97336000007
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

        $this->staff('+97336000001', 'عبدالله أحمد الدوسري', 'male', 'both', 'super_admin', 'admin@ahlalquran.bh');
        $this->staff('+97336000002', 'أ. خالد إبراهيم الرميحي', 'male', 'male', 'supervisor');
        $this->staff('+97336000006', 'أ. فاطمة الشيخ', 'female', 'female', 'supervisor');
        $maleTeacher = $this->staff('+97336000003', 'الشيخ يوسف علي المناعي', 'male', 'male', 'teacher');
        $femaleTeacher = $this->staff('+97336000007', 'أ. زينب حسن العريض', 'female', 'female', 'teacher');
        Teacher::updateOrCreate(['user_id' => $maleTeacher->id], ['gender' => 'male', 'specialization' => 'رواية حفص عن عاصم', 'is_active' => true]);
        Teacher::updateOrCreate(['user_id' => $femaleTeacher->id], ['gender' => 'female', 'specialization' => 'التجويد والتلاوة', 'is_active' => true]);

        $guardian = User::updateOrCreate(['phone' => '+97336000005'], [
            'name' => 'محمد سلمان الجودر', 'password' => null, 'gender' => 'male', 'locale' => 'ar', 'is_active' => true, 'phone_verified_at' => now(),
        ]);
        $guardian->syncRoles(['guardian']);

        $studentUser = User::updateOrCreate(['phone' => '+97336000004'], [
            'name' => 'أحمد محمد الجودر', 'password' => null, 'gender' => 'male', 'locale' => 'ar', 'is_active' => true, 'phone_verified_at' => now(),
        ]);
        $studentUser->syncRoles(['student']);

        // Halls: one per track and one shared by schedule.
        $boysHall = Location::updateOrCreate(['code' => 'B01'], ['name' => 'قاعة البنين — الطابق الأرضي', 'gender' => 'male', 'capacity' => 25, 'address' => 'مركز أهل القرآن، سار', 'map_link' => 'https://maps.google.com/?q=26.1886,50.4867', 'is_active' => true]);
        $girlsHall = Location::updateOrCreate(['code' => 'G01'], ['name' => 'قاعة البنات — الطابق الأول', 'gender' => 'female', 'capacity' => 25, 'address' => 'مركز أهل القرآن، سار', 'map_link' => 'https://maps.google.com/?q=26.1886,50.4867', 'is_active' => true]);
        Location::updateOrCreate(['code' => 'S01'], ['name' => 'القاعة الكبرى (مشتركة حسب الجدول)', 'gender' => 'shared', 'capacity' => 80, 'address' => 'مركز أهل القرآن، سار', 'map_link' => 'https://maps.google.com/?q=26.1886,50.4867', 'is_active' => true]);

        // Packages: boys and girls, never mixed.
        $start = now()->startOfMonth();
        $common = ['min_age' => 7, 'max_age' => 14, 'seats' => 30, 'price_fils' => 20000, 'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addMonths(4)->toDateString(), 'term' => '2026-2027 / 1', 'plan_ayahs' => 600, 'status' => 'open', 'memorization_direction' => 'backward'];
        $boys = Package::updateOrCreate(['name' => 'باقة الحفظ — بنين'], $common + ['name_ar' => 'باقة الحفظ — بنين', 'name_en' => 'Memorization — Boys', 'gender' => 'male', 'days' => ['sat', 'mon', 'wed'], 'start_time' => '16:00:00', 'end_time' => '17:30:00']);
        $girls = Package::updateOrCreate(['name' => 'باقة الحفظ — بنات'], $common + ['name_ar' => 'باقة الحفظ — بنات', 'name_en' => 'Memorization — Girls', 'gender' => 'female', 'days' => ['sun', 'tue', 'thu'], 'start_time' => '16:00:00', 'end_time' => '17:30:00']);

        $boysCircle = Lesson::updateOrCreate(['name' => 'حلقة الإمام نافع'], ['package_id' => $boys->id, 'teacher_id' => $maleTeacher->id, 'location_id' => $boysHall->id, 'days' => $boys->days, 'start_time' => $boys->start_time, 'end_time' => $boys->end_time, 'capacity' => 15, 'start_date' => $boys->start_date->toDateString(), 'end_date' => $boys->end_date?->toDateString(), 'status' => 'active']);
        $girlsCircle = Lesson::updateOrCreate(['name' => 'حلقة أم المؤمنين خديجة'], ['package_id' => $girls->id, 'teacher_id' => $femaleTeacher->id, 'location_id' => $girlsHall->id, 'days' => $girls->days, 'start_time' => $girls->start_time, 'end_time' => $girls->end_time, 'capacity' => 15, 'start_date' => $girls->start_date->toDateString(), 'end_date' => $girls->end_date?->toDateString(), 'status' => 'active']);

        // Siblings under one guardian login, one in each track.
        $boy = $this->student('S26DEMO1', 'أحمد محمد سلمان الجودر', 'male', 12, $guardian, $studentUser);
        $girl = $this->student('S26DEMO2', 'فاطمة محمد سلمان الجودر', 'female', 10, $guardian);

        foreach ([[$boysCircle, $boy], [$girlsCircle, $girl]] as [$circle, $student]) {
            LessonStudent::updateOrCreate(['lesson_id' => $circle->id, 'student_id' => $student->id], ['status' => 'active', 'joined_at' => $circle->start_date->toDateString()]);
            app(SessionGenerator::class)->generateFor($circle);
        }

        $this->history($boysCircle, 'male', ['يوسف', 'عبدالله', 'حسن', 'سلمان', 'عيسى', 'راشد'], $maleTeacher);
        $this->history($girlsCircle, 'female', ['مريم', 'زينب', 'نور', 'سارة', 'هدى', 'ريم'], $femaleTeacher);
    }

    /**
     * A small class per circle plus three weeks of past sessions with attendance, daily scores and
     * ledger entries, so the dashboard, profiles and reports have something to show. Deterministic and idempotent.
     */
    private function history(Lesson $circle, string $gender, array $names, User $teacher): void
    {
        $families = ['الدوسري', 'المناعي', 'البوعينين', 'الكعبي', 'العريض', 'الرميحي'];
        $students = collect($names)->map(function ($first, $i) use ($circle, $gender, $families) {
            $no = sprintf('S26D%s%02d', $gender === 'male' ? 'B' : 'G', $i + 1);
            $guardian = User::updateOrCreate(['phone' => sprintf('+973361%s%04d', $gender === 'male' ? '1' : '2', $i + 1)], [
                'name' => 'محمد '.$families[$i], 'password' => null, 'gender' => 'male', 'locale' => 'ar', 'is_active' => true, 'phone_verified_at' => now(),
            ]);
            $guardian->syncRoles(['guardian']);
            $s = $this->student($no, "{$first} محمد {$families[$i]}", $gender, 8 + $i, $guardian);
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

    private function staff(string $phone, string $name, string $gender, string $track, string $role, ?string $email = null): User
    {
        $user = User::updateOrCreate(['phone' => $phone], [
            'name' => $name, 'email' => $email, 'password' => 'password', 'gender' => $gender, 'track' => $track,
            'locale' => 'ar', 'is_active' => true, 'phone_verified_at' => now(),
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
            'memorization_level' => 'juz_amma',
            'locale' => 'ar',
            'status' => 'active',
        ]);
        Wallet::firstOrCreate(['student_id' => $student->id], ['balance_fils' => 0]);

        return $student;
    }
}
