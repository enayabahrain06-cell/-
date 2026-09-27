<?php

namespace Database\Factories;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Student> */
class StudentFactory extends Factory
{
    private const FIRST_M = ['أحمد', 'محمد', 'علي', 'يوسف', 'عبدالله', 'حسن', 'حسين', 'خالد', 'سلمان', 'عيسى', 'عمر', 'إبراهيم', 'حمد', 'راشد', 'ناصر'];

    private const FIRST_F = ['فاطمة', 'مريم', 'زينب', 'نور', 'سارة', 'عائشة', 'هدى', 'لطيفة', 'شيخة', 'منيرة', 'دانة', 'ريم', 'حصة', 'عالية', 'أمل'];

    private const FAMILY = ['الدوسري', 'البوعينين', 'المناعي', 'الجودر', 'الكعبي', 'العريض', 'الرميحي', 'المرزوق', 'الزياني', 'بوخماس', 'الحداد', 'الشيخ', 'الخاجة', 'البنعلي', 'المؤيد', 'العصفور', 'الحمر', 'المعاودة'];

    public function definition(): array
    {
        $gender = fake()->randomElement(['male', 'female']);
        $first = fake()->randomElement($gender === 'male' ? self::FIRST_M : self::FIRST_F);
        $father = fake()->randomElement(self::FIRST_M);
        $family = fake()->randomElement(self::FAMILY);

        return [
            'student_no' => 'S26'.fake()->unique()->numerify('#####'),
            'full_name' => "{$first} {$father} {$family}",
            'birth_date' => fake()->dateTimeBetween('-16 years', '-6 years')->format('Y-m-d'),
            'gender' => $gender,
            'student_phone' => null,
            'guardian_name' => "{$father} ".fake()->randomElement(self::FIRST_M)." {$family}",
            'guardian_phone' => '+9733'.fake()->unique()->numerify('#######'),
            'memorization_level' => fake()->randomElement(['none', 'juz_amma', 'juz_tabarak', 'five_ajza']),
            'locale' => 'ar',
            'status' => 'active',
        ];
    }
}
