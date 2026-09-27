<?php

namespace Database\Seeders;

use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;

/** One demo account per role. Password for staff: "password". */
class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(['phone' => '+97336000001'], [
            'name' => 'عبدالله أحمد الدوسري', 'email' => 'admin@ahlalquran.bh', 'password' => 'password', 'gender' => 'male', 'locale' => 'ar', 'is_active' => true, 'phone_verified_at' => now(),
        ]);
        $admin->syncRoles(['super_admin']);

        $supervisor = User::updateOrCreate(['phone' => '+97336000002'], [
            'name' => 'مريم خالد البوعينين', 'password' => 'password', 'gender' => 'female', 'locale' => 'ar', 'is_active' => true, 'phone_verified_at' => now(),
        ]);
        $supervisor->syncRoles(['supervisor']);

        $teacher = User::updateOrCreate(['phone' => '+97336000003'], [
            'name' => 'الشيخ يوسف علي المناعي', 'password' => 'password', 'gender' => 'male', 'locale' => 'ar', 'is_active' => true, 'phone_verified_at' => now(),
        ]);
        $teacher->syncRoles(['teacher']);
        Teacher::updateOrCreate(['user_id' => $teacher->id], ['gender' => 'male', 'specialization' => 'رواية حفص عن عاصم', 'is_active' => true]);

        $guardian = User::updateOrCreate(['phone' => '+97336000005'], [
            'name' => 'محمد سلمان الجودر', 'password' => null, 'gender' => 'male', 'locale' => 'ar', 'is_active' => true, 'phone_verified_at' => now(),
        ]);
        $guardian->syncRoles(['guardian']);

        $studentUser = User::updateOrCreate(['phone' => '+97336000004'], [
            'name' => 'أحمد محمد الجودر', 'password' => null, 'gender' => 'male', 'locale' => 'ar', 'is_active' => true, 'phone_verified_at' => now(),
        ]);
        $studentUser->syncRoles(['student']);

        $student = Student::withTrashed()->updateOrCreate(['student_no' => 'S26DEMO1'], [
            'user_id' => $studentUser->id,
            'guardian_user_id' => $guardian->id,
            'full_name' => 'أحمد محمد سلمان الجودر',
            'birth_date' => now()->subYears(12)->toDateString(),
            'gender' => 'male',
            'student_phone' => $studentUser->phone,
            'guardian_name' => $guardian->name,
            'guardian_phone' => $guardian->phone,
            'memorization_level' => 'juz_amma',
            'locale' => 'ar',
            'status' => 'active',
        ]);

        Wallet::firstOrCreate(['student_id' => $student->id], ['balance_fils' => 0]);
    }
}
