<?php

use App\Enums\PaymentMethod;
use App\Models\AuditLog;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\MessageLog;
use App\Models\Package;
use App\Models\Student;
use App\Services\Wallet\WalletService;

beforeEach(function () {
    $this->w = app(WalletService::class);
    $this->boysPkg = Package::factory()->create(['gender' => 'male', 'term' => '1447']);
    $this->girlsPkg = Package::factory()->girls()->create(['term' => '1448']);
    $this->boy = Student::factory()->male()->create(['full_name' => 'علي']);
    $this->girl = Student::factory()->female()->create(['full_name' => 'فاطمة']);
    $boysLesson = Lesson::factory()->create(['package_id' => $this->boysPkg->id]);
    $girlsLesson = Lesson::factory()->create(['package_id' => $this->girlsPkg->id]);
    LessonStudent::create(['lesson_id' => $boysLesson->id, 'student_id' => $this->boy->id, 'joined_at' => today(), 'status' => 'active']);
    LessonStudent::create(['lesson_id' => $girlsLesson->id, 'student_id' => $this->girl->id, 'joined_at' => today(), 'status' => 'active']);

    // Boy: 20.000 overdue by 10 days. Girl: 30.000 due in 3 days; 5.000 paid this month, 10.000 last month.
    $this->w->createInvoice($this->boy, $this->boysPkg, 20000, now()->subDays(10), 'رسوم');
    $this->w->createInvoice($this->girl, $this->girlsPkg, 30000, now()->addDays(3), 'رسوم');
    $this->w->recordPayment($this->girl, 5000, PaymentMethod::Cash, ['notify' => false]);
    $this->w->recordPayment($this->girl, 10000, PaymentMethod::Cash, ['notify' => false, 'paid_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(5)]);
});

it('summarises collections, overdue and due-this-week with the top overdue students', function () {
    actingAsRole('super_admin');
    $d = $this->getJson('/api/dashboard/fees')->assertOk()->json('data');

    expect($d['collected'])->toBe(['this_month_fils' => 5000, 'last_month_fils' => 10000, 'change_percent' => -50])
        ->and($d['overdue'])->toBe(['amount_fils' => 20000, 'students' => 1, 'invoices' => 1])
        ->and($d['due_this_week']['amount_fils'])->toBe(15000) // 30.000 minus 15.000 settled
        ->and($d['top_overdue'][0])->toMatchArray(['student_id' => $this->boy->id, 'name' => 'علي', 'days_overdue' => 10, 'outstanding_fils' => 20000, 'has_phone' => true])
        ->and($d['can_remind'])->toBeTrue();
});

it('scopes fees by track and term and needs wallet access', function () {
    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);
    $d = $this->getJson('/api/dashboard/fees')->assertOk()->json('data');
    expect($d['overdue']['students'])->toBe(0)->and($d['collected']['this_month_fils'])->toBe(5000);

    actingAsRole('super_admin');
    expect($this->getJson('/api/dashboard/fees?term=1448')->json('data.overdue.students'))->toBe(0)
        ->and($this->getJson('/api/dashboard/fees?term=1447')->json('data.overdue.students'))->toBe(1);

    actingAsRole('teacher');
    $this->getJson('/api/dashboard/fees')->assertForbidden();
});

it('reminds the family once a day, and refuses without a phone or overdue dues', function () {
    $admin = actingAsRole('super_admin');
    $this->postJson("/api/dashboard/fees/remind/{$this->boy->id}")->assertOk()->assertJsonPath('sent', 1);
    $log = MessageLog::where('student_id', $this->boy->id)->sole();
    expect($log->type->value)->toBe('payment_due_reminder')->and($log->recipient_phone)->toBe($this->boy->guardian_phone)
        ->and(AuditLog::where('action', 'invoice.reminder_sent')->where('user_id', $admin->id)->exists())->toBeTrue()
        ->and($this->boy->invoices()->first()->reminder_after_sent_at)->toBeNull();

    $this->postJson("/api/dashboard/fees/remind/{$this->boy->id}")->assertUnprocessable()->assertJsonValidationErrors('student');
    expect(MessageLog::where('student_id', $this->boy->id)->count())->toBe(1);

    $this->postJson("/api/dashboard/fees/remind/{$this->girl->id}")->assertUnprocessable(); // nothing overdue

    $this->boy->update(['guardian_phone' => '']);
    $this->travel(1)->days();
    $this->postJson("/api/dashboard/fees/remind/{$this->boy->id}")->assertUnprocessable();

    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);
    $this->postJson("/api/dashboard/fees/remind/{$this->boy->id}")->assertForbidden(); // other track
});
