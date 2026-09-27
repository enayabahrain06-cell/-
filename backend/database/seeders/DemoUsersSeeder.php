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
class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
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
