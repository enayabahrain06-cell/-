<?php

use App\Models\Student;

it('numbers new students after the highest numeric suffix, ignoring demo numbers like S26DEMO1', function () {
    $year = now()->format('y');
    Student::factory()->create(['student_no' => "S{$year}00007"]);
    Student::factory()->create(['student_no' => "S{$year}DEMO9"]);

    expect(Student::nextStudentNo())->toBe("S{$year}00008");
});
