<?php

use App\Models\AcademicTerm;
use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Level;
use App\Models\Location;
use App\Models\LocationBooking;
use App\Models\Package;
use App\Models\Student;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->term = AcademicTerm::create(['name_ar' => 'الفصل الأول', 'name_en' => 'Term 1', 'is_current' => true, 'start_date' => today()->subMonth()->toDateString(), 'end_date' => today()->addMonths(3)->toDateString()]);
    $this->other = AcademicTerm::create(['name_ar' => 'الفصل الثاني', 'name_en' => 'Term 2', 'is_current' => false]);
    $this->level = Level::create(['name_ar' => 'المستوى الأول', 'name_en' => 'Level 1']);
    $this->level2 = Level::create(['name_ar' => 'المستوى الثاني', 'name_en' => 'Level 2']);
    $this->pkg = Package::factory()->create(['academic_term_id' => $this->term->id]);
    $this->lesson = Lesson::factory()->create(['name' => 'صف أ', 'package_id' => $this->pkg->id, 'level_id' => $this->level->id]);
    $this->boys = Student::factory()->male()->count(3)->create(['birth_date' => today()->subYears(10)->toDateString()]);
    foreach ($this->boys as $s) {
        LessonStudent::create(['lesson_id' => $this->lesson->id, 'student_id' => $s->id, 'joined_at' => today()->subMonth(), 'status' => 'active']);
    }
    $this->girl = Student::factory()->female()->create(['birth_date' => today()->subYears(10)->toDateString()]);
    $this->room = Location::factory()->create(['gender' => 'shared']);
});

function makeActivity(array $attrs = []): Activity
{
    return Activity::create([
        'type' => 'program', 'academic_term_id' => test()->term->id, 'name_ar' => 'برنامج الإجازة', 'starts_on' => today()->subDays(2)->toDateString(),
        'ends_on' => today()->addDays(5)->toDateString(), 'price_fils' => 5000, 'status' => 'open', ...$attrs,
    ]);
}

function register(Activity $a, array $ids, array $extra = []): \Illuminate\Testing\TestResponse
{
    return test()->postJson("/api/activities/{$a->id}/registrations", ['student_ids' => $ids, ...$extra]);
}

it('lists, creates, edits and deletes programs and trips of the selected term only', function () {
    actingAsRole('supervisor');
    $res = $this->postJson('/api/activities', ['type' => 'program', 'academic_term_id' => $this->term->id, 'name_ar' => 'برنامج الصيف', 'starts_on' => today()->toDateString(),
        'ends_on' => today()->addWeek()->toDateString(), 'seats' => 20, 'price_fils' => 3000, 'has_book' => true, 'book_title' => 'كتاب', 'book_price_fils' => 1500, 'status' => 'open'])->assertCreated();
    $this->postJson('/api/activities', ['type' => 'trip', 'academic_term_id' => $this->term->id, 'name_ar' => 'رحلة العرين', 'starts_on' => today()->addDays(3)->toDateString(), 'place' => 'محمية العرين'])->assertCreated();
    $this->postJson('/api/activities', ['type' => 'trip', 'academic_term_id' => $this->other->id, 'name_ar' => 'رحلة أخرى', 'starts_on' => today()->toDateString()])->assertCreated();

    $this->getJson('/api/activities?type=program')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'برنامج الصيف')->assertJsonPath('data.0.book_price_fils', 1500);
    $this->getJson('/api/activities?type=trip')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.place', 'محمية العرين');
    $this->getJson("/api/activities?type=trip&term_id={$this->other->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'رحلة أخرى');
    $this->getJson('/api/activities')->assertStatus(422);

    $id = $res->json('data.id');
    $this->putJson("/api/activities/{$id}", ['status' => 'closed', 'ends_on' => today()->subDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('ends_on');
    $this->putJson("/api/activities/{$id}", ['status' => 'closed', 'has_book' => false])->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.book_price_fils', 0);
    $this->putJson("/api/activities/{$id}", ['type' => 'trip'])->assertStatus(422);
    expect(AuditLog::where('action', 'activity.updated')->exists())->toBeTrue();
    $this->deleteJson("/api/activities/{$id}")->assertOk();
    expect(Activity::find($id))->toBeNull();
});

it('refuses programs that clash with another use of the room', function () {
    actingAsRole('supervisor');
    $base = ['type' => 'program', 'academic_term_id' => $this->term->id, 'starts_on' => today()->addDay()->toDateString(), 'ends_on' => today()->addDays(4)->toDateString(),
        'location_id' => $this->room->id, 'start_time' => '16:00', 'end_time' => '18:00'];
    $first = $this->postJson('/api/activities', [...$base, 'name_ar' => 'الأول'])->assertCreated()->json('data.id');
    $this->postJson('/api/activities', [...$base, 'name_ar' => 'الثاني', 'start_time' => '17:00', 'end_time' => '19:00'])->assertStatus(422)->assertJsonValidationErrors('location_id');
    $this->postJson('/api/activities', [...$base, 'name_ar' => 'الثالث', 'start_time' => '18:00', 'end_time' => '19:00'])->assertCreated();
    // Editing the program itself does not clash with itself.
    $this->putJson("/api/activities/{$first}", ['name_ar' => 'الأول المعدل'])->assertOk();

    LocationBooking::create(['location_id' => $this->room->id, 'title' => 'حفل', 'booking_date' => today()->addDays(10)->toDateString(), 'start_time' => '10:00', 'end_time' => '12:00', 'kind' => 'event']);
    $this->postJson('/api/activities', [...$base, 'name_ar' => 'الرابع', 'starts_on' => today()->addDays(10)->toDateString(), 'ends_on' => null, 'start_time' => '11:00', 'end_time' => '13:00'])
        ->assertStatus(422)->assertJsonValidationErrors('location_id');
});

it('registers students and bills the fee through an ordinary invoice of the activity term (both types)', function () {
    $user = actingAsRole('supervisor');
    $program = makeActivity();
    $trip = makeActivity(['type' => 'trip', 'name_ar' => 'رحلة البحر', 'price_fils' => 2000, 'ends_on' => null, 'starts_on' => today()->toDateString()]);

    register($program, [$this->boys[0]->id])->assertCreated()->assertJsonPath('data.0.outcome', 'register');
    register($trip, [$this->boys[0]->id])->assertCreated();
    $reg = ActivityRegistration::where('activity_id', $program->id)->first();
    $invoice = Invoice::find($reg->fee_invoice_id);
    expect($invoice->amount_fils)->toBe(5000)
        ->and($invoice->description)->toBe('رسوم برنامج: برنامج الإجازة')
        ->and($invoice->academic_term_id)->toBe($this->term->id)
        ->and($invoice->issued_by)->toBe($user->id)
        ->and(Invoice::find(ActivityRegistration::where('activity_id', $trip->id)->value('fee_invoice_id'))->description)->toBe('رسوم رحلة: رحلة البحر')
        ->and($this->boys[0]->wallet()->value('balance_fils'))->toBe(-7000);

    // A free activity issues no invoice; registering twice does nothing.
    $free = makeActivity(['name_ar' => 'مجاني', 'price_fils' => 0]);
    register($free, [$this->boys[1]->id])->assertCreated();
    expect(ActivityRegistration::where('activity_id', $free->id)->value('fee_invoice_id'))->toBeNull();
    register($free, [$this->boys[1]->id])->assertCreated()->assertJsonPath('data.0.outcome', 'already');
    expect(ActivityRegistration::where('activity_id', $free->id)->count())->toBe(1);

    $roster = $this->getJson("/api/activities/{$program->id}/registrations")->assertOk();
    $roster->assertJsonPath('data.0.money.status', 'unpaid')->assertJsonPath('data.0.fee_invoice.amount_fils', 5000)
        ->assertJsonPath('data.0.class.name', 'صف أ')->assertJsonPath('totals.money.remaining_fils', 5000);
});

it('checks eligibility: open, active, gender, age, level, track and seats with a waiting list', function () {
    actingAsRole('supervisor');
    $a = makeActivity(['gender' => 'male', 'min_age' => 8, 'max_age' => 12, 'level_id' => $this->level->id, 'seats' => 2]);
    $old = Student::factory()->male()->create(['birth_date' => today()->subYears(15)->toDateString()]);
    LessonStudent::create(['lesson_id' => $this->lesson->id, 'student_id' => $old->id, 'joined_at' => today(), 'status' => 'active']);
    $noLevel = Student::factory()->male()->create(['birth_date' => today()->subYears(10)->toDateString()]);
    $inactive = Student::factory()->male()->create(['status' => 'inactive']);

    $check = $this->getJson("/api/activities/{$a->id}/candidates?".http_build_query(['student_ids' => [$this->girl->id, $old->id, $noLevel->id, $inactive->id, ...$this->boys->pluck('id')]]))->assertOk();
    $by = collect($check->json('data'))->mapWithKeys(fn ($r) => [$r['student']['id'] => $r['outcome'].':'.$r['reason']]);
    expect($by[$this->girl->id])->toBe('refused:gender')
        ->and($by[$old->id])->toBe('refused:age')
        ->and($by[$noLevel->id])->toBe('refused:level')
        ->and($by[$inactive->id])->toBe('refused:inactive')
        ->and($by[$this->boys[0]->id])->toBe('register:')
        ->and($by[$this->boys[2]->id])->toBe('waitlist:full');

    // A whole class at once.
    $this->getJson("/api/activities/{$a->id}/candidates?lesson_id={$this->lesson->id}")->assertOk()->assertJsonCount(4, 'data');

    $res = register($a, [$this->girl->id, ...$this->boys->pluck('id')])->assertCreated();
    expect(collect($res->json('data'))->pluck('outcome')->all())->toBe(['refused', 'register', 'register', 'waitlist'])
        ->and(ActivityRegistration::where('activity_id', $a->id)->where('status', 'waitlist')->value('fee_invoice_id'))->toBeNull();

    // Closed activities take nobody.
    $a->update(['status' => 'closed']);
    register($a, [$noLevel->id])->assertCreated()->assertJsonPath('data.0.reason', 'not_open');

    // A boys-track supervisor cannot register a girl in a mixed activity.
    actingAsRole('supervisor', ['track' => 'male']);
    $mixed = makeActivity(['gender' => 'mixed']);
    register($mixed, [$this->girl->id])->assertCreated()->assertJsonPath('data.0.reason', 'track');
});

it('cancels unpaid registrations with their invoice, refuses paid ones, and fills a freed seat from the waiting list', function () {
    actingAsRole('supervisor');
    $a = makeActivity(['seats' => 1]);
    register($a, [$this->boys[0]->id, $this->boys[1]->id])->assertCreated();
    $first = ActivityRegistration::where('student_id', $this->boys[0]->id)->first();
    $waiting = ActivityRegistration::where('student_id', $this->boys[1]->id)->first();
    expect($waiting->status)->toBe('waitlist');
    $this->postJson("/api/activities/{$a->id}/registrations/{$waiting->id}/confirm")->assertStatus(422);

    $this->postJson("/api/activities/{$a->id}/registrations/{$first->id}/cancel")->assertOk();
    expect($first->fresh()->status)->toBe('cancelled')
        ->and(Invoice::find($first->fee_invoice_id)->status->value)->toBe('cancelled')
        ->and($this->boys[0]->wallet()->value('balance_fils'))->toBe(0);

    $this->postJson("/api/activities/{$a->id}/registrations/{$waiting->id}/confirm")->assertOk();
    $waiting->refresh();
    expect($waiting->status)->toBe('registered')->and(Invoice::find($waiting->fee_invoice_id)->amount_fils)->toBe(5000);

    // Paid through the existing payments flow, the registration can no longer be cancelled.
    $this->postJson('/api/payments', ['student_id' => $this->boys[1]->id, 'amount' => '2', 'method' => 'cash', 'notify' => false])->assertCreated();
    expect(Invoice::find($waiting->fee_invoice_id)->status->value)->toBe('partial');
    $this->getJson("/api/activities/{$a->id}/registrations")->assertOk()->assertJsonPath('data.0.money.status', 'partial')->assertJsonPath('data.0.money.remaining_fils', 3000);
    $this->postJson("/api/activities/{$a->id}/registrations/{$waiting->id}/cancel")->assertStatus(422);
    expect($waiting->fresh()->status)->toBe('registered');

    // A cancelled student can register again, with a new invoice.
    register($a, [$this->boys[0]->id])->assertCreated()->assertJsonPath('data.0.outcome', 'waitlist');
});

it('delivers the program book in bulk, billing it at delivery or at registration, and guards undo and cancel', function () {
    actingAsRole('supervisor');
    $a = makeActivity(['has_book' => true, 'book_title' => 'كتاب البرنامج', 'book_price_fils' => 1500]);
    register($a, [$this->boys[0]->id, $this->boys[1]->id])->assertCreated();
    register($a, [$this->boys[2]->id], ['charge_book' => true])->assertCreated();
    $r2 = ActivityRegistration::where('student_id', $this->boys[2]->id)->first();
    expect(Invoice::find($r2->book_invoice_id)->description)->toBe('كتاب برنامج برنامج الإجازة: كتاب البرنامج');

    $this->postJson("/api/activities/{$a->id}/book-deliveries", ['student_ids' => [...$this->boys->pluck('id'), $this->girl->id]])->assertOk()->assertJsonPath('delivered', 3);
    $r0 = ActivityRegistration::where('student_id', $this->boys[0]->id)->first();
    expect(Invoice::find($r0->book_invoice_id)->amount_fils)->toBe(1500)
        ->and(Invoice::where('student_id', $this->boys[2]->id)->count())->toBe(2); // not billed twice

    $roster = collect($this->getJson("/api/activities/{$a->id}/registrations")->assertOk()->json('data'))->keyBy('student.id');
    expect($roster[$this->boys[0]->id]['book']['delivered_at'])->toBe(today()->toDateString())
        ->and($roster[$this->boys[0]->id]['book_invoice']['status'])->toBe('open')
        ->and($roster[$this->boys[0]->id]['money']['total_fils'])->toBe(6500);

    $this->postJson("/api/activities/{$a->id}/registrations/{$r0->id}/cancel")->assertStatus(422);
    $delivery = \App\Models\ActivityBookDelivery::where('student_id', $this->boys[0]->id)->first();
    $this->deleteJson("/api/activities/{$a->id}/book-deliveries/{$delivery->id}")->assertOk();
    expect(Invoice::find($r0->book_invoice_id)->status->value)->toBe('cancelled')->and($r0->fresh()->book_invoice_id)->toBeNull();

    $this->postJson('/api/payments', ['student_id' => $this->boys[1]->id, 'amount' => '6.5', 'method' => 'cash', 'notify' => false])->assertCreated();
    $d1 = \App\Models\ActivityBookDelivery::where('student_id', $this->boys[1]->id)->first();
    $this->deleteJson("/api/activities/{$a->id}/book-deliveries/{$d1->id}")->assertStatus(422);

    $noBook = makeActivity(['name_ar' => 'بلا كتاب']);
    $this->postJson("/api/activities/{$noBook->id}/book-deliveries", ['student_ids' => [$this->boys[0]->id]])->assertStatus(422);
});

it('records attendance on the activity days only, and summarises rates per student and per date (both types)', function () {
    actingAsRole('teacher');
    $a = makeActivity();
    $trip = makeActivity(['type' => 'trip', 'starts_on' => today()->toDateString(), 'ends_on' => null]);
    foreach ([$a, $trip] as $x) {
        foreach ($this->boys as $s) {
            ActivityRegistration::create(['activity_id' => $x->id, 'student_id' => $s->id, 'status' => 'registered', 'registered_at' => now()]);
        }
    }
    $rows = fn (string $s0, string $s1) => [['student_id' => $this->boys[0]->id, 'status' => $s0], ['student_id' => $this->boys[1]->id, 'status' => $s1], ['student_id' => $this->boys[2]->id, 'status' => null]];

    $this->putJson("/api/activities/{$a->id}/attendance", ['date' => today()->subDays(2)->toDateString(), 'rows' => $rows('present', 'absent')])->assertOk();
    $this->putJson("/api/activities/{$a->id}/attendance", ['date' => today()->toDateString(), 'rows' => $rows('late', 'excused')])->assertOk();
    $this->putJson("/api/activities/{$a->id}/attendance", ['date' => today()->subDays(3)->toDateString(), 'rows' => $rows('present', 'present')])->assertStatus(422)->assertJsonValidationErrors('date');
    $this->putJson("/api/activities/{$a->id}/attendance", ['date' => today()->addDay()->toDateString(), 'rows' => $rows('present', 'present')])->assertStatus(422)->assertJsonValidationErrors('date');
    $this->putJson("/api/activities/{$a->id}/attendance", ['date' => today()->toDateString(), 'rows' => [['student_id' => $this->girl->id, 'status' => 'present']]])->assertStatus(422);
    $this->putJson("/api/activities/{$trip->id}/attendance", ['date' => today()->toDateString(), 'rows' => $rows('present', 'present')])->assertOk();

    $this->getJson("/api/activities/{$a->id}/attendance?date=".today()->toDateString())->assertOk()
        ->assertJsonPath('date', today()->toDateString())->assertJsonCount(3, 'dates')->assertJsonCount(3, 'data');
    $summary = collect($this->getJson("/api/activities/{$a->id}/attendance/summary")->assertOk()->json('data'))->keyBy('date');
    expect($summary[today()->toDateString()]['late'])->toBe(1)->and($summary[today()->subDays(2)->toDateString()]['absent'])->toBe(1);
    $roster = collect($this->getJson("/api/activities/{$a->id}/registrations")->assertOk()->json('data'))->keyBy('student.id');
    expect($roster[$this->boys[0]->id]['attendance']['rate'])->toEqual(100)
        ->and($roster[$this->boys[1]->id]['attendance']['rate'])->toEqual(0)   // absent once, excused once
        ->and($roster[$this->boys[2]->id]['attendance']['rate'])->toBeNull()
        // teachers see no money
        ->and($roster[$this->boys[0]->id]['money'])->toBeNull();
    expect(collect($this->getJson("/api/activities/{$trip->id}/attendance/summary")->json('data'))->first()['present'])->toBe(2);
});

it('evaluates registered students out of 100 and shows the results', function () {
    actingAsRole('teacher');
    $a = makeActivity();
    ActivityRegistration::create(['activity_id' => $a->id, 'student_id' => $this->boys[0]->id, 'status' => 'registered', 'registered_at' => now()]);
    ActivityRegistration::create(['activity_id' => $a->id, 'student_id' => $this->boys[1]->id, 'status' => 'waitlist', 'registered_at' => now()]);

    $this->putJson("/api/activities/{$a->id}/evaluations", ['rows' => [['student_id' => $this->boys[0]->id, 'score' => 101]]])->assertStatus(422);
    $this->putJson("/api/activities/{$a->id}/evaluations", ['rows' => [['student_id' => $this->boys[1]->id, 'score' => 90]]])->assertStatus(422);
    $this->putJson("/api/activities/{$a->id}/evaluations", ['rows' => [['student_id' => $this->boys[0]->id, 'score' => 92, 'grade' => 'ممتاز', 'notes' => 'متميز']]])->assertOk();
    $this->getJson("/api/activities/{$a->id}/registrations")->assertOk()->assertJsonPath('data.0.evaluation.score', 92)->assertJsonPath('data.0.evaluation.grade', 'ممتاز')
        ->assertJsonPath('totals.evaluated', 1);
    // Clearing every field removes the evaluation.
    $this->putJson("/api/activities/{$a->id}/evaluations", ['rows' => [['student_id' => $this->boys[0]->id, 'score' => null, 'grade' => '', 'notes' => null]]])->assertOk();
    $this->getJson("/api/activities/{$a->id}/registrations")->assertJsonPath('data.0.evaluation', null);
});

it('enforces the permissions of each screen', function () {
    $a = makeActivity();
    $reg = ActivityRegistration::create(['activity_id' => $a->id, 'student_id' => $this->boys[0]->id, 'status' => 'registered', 'registered_at' => now()]);

    actingAsRole('teacher');
    $this->getJson('/api/activities?type=program')->assertOk()->assertJsonPath('can.manage', false)->assertJsonPath('can.attendance', true);
    $this->postJson('/api/activities', ['type' => 'program', 'academic_term_id' => $this->term->id, 'name_ar' => 'x', 'starts_on' => today()->toDateString()])->assertForbidden();
    $this->putJson("/api/activities/{$a->id}", ['status' => 'closed'])->assertForbidden();
    register($a, [$this->boys[1]->id])->assertForbidden();
    $this->getJson("/api/activities/{$a->id}/candidates?lesson_id={$this->lesson->id}")->assertForbidden();
    $this->postJson("/api/activities/{$a->id}/registrations/{$reg->id}/cancel")->assertForbidden();
    $this->postJson("/api/activities/{$a->id}/book-deliveries", ['student_ids' => [$this->boys[0]->id]])->assertForbidden();

    actingAsRole('student');
    $this->getJson('/api/activities?type=program')->assertForbidden();
    $this->getJson("/api/activities/{$a->id}/registrations")->assertForbidden();
    $this->getJson("/api/activities/{$a->id}/attendance")->assertForbidden();
    $this->putJson("/api/activities/{$a->id}/attendance", ['date' => today()->toDateString(), 'rows' => [['student_id' => $this->boys[0]->id, 'status' => 'present']]])->assertForbidden();
    $this->putJson("/api/activities/{$a->id}/evaluations", ['rows' => [['student_id' => $this->boys[0]->id, 'score' => 5]]])->assertForbidden();

    // A girls-track supervisor cannot open a boys-only program.
    actingAsRole('supervisor', ['track' => 'female']);
    $boysOnly = makeActivity(['gender' => 'male']);
    $this->getJson('/api/activities?type=program')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/activities/{$boysOnly->id}/registrations")->assertNotFound();
});

it('rolls the activities migration back', function () {
    do {
        Artisan::call('migrate:rollback', ['--step' => 1]);
    } while (Schema::hasTable('activities'));
    expect(Schema::hasTable('activity_registrations'))->toBeFalse()->and(Schema::hasTable('activity_evaluations'))->toBeFalse();
    Artisan::call('migrate');
    expect(Schema::hasTable('activities'))->toBeTrue();
});
