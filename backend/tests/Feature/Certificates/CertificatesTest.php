<?php

use Ahl\Certificates\Contracts\FileStore;
use App\Enums\MediaCollection;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Media;
use App\Models\MessageLog;
use App\Models\Student;
use App\Models\User;
use App\Services\Progress\ProgressService;
use App\Services\SettingsService;
use App\Support\Quran;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['ahl.media.disk' => 'local']);
    $this->student = Student::factory()->male()->create(['guardian_phone' => '+97333000001']);
    $this->other = Student::factory()->male()->create();
});

function guardianOf(Student $student): User
{
    $guardian = User::factory()->withoutPassword()->create();
    $guardian->assignRole('guardian');
    $student->update(['guardian_user_id' => $guardian->id]);

    return $guardian;
}

it('drafts, approves with PDF, WhatsApp and audit, then revokes and stamps', function () {
    $supervisor = actingAsRole('supervisor');

    $id = $this->postJson('/api/certificates', ['recipient_ids' => [$this->student->id], 'type' => 'excellence', 'achievement' => 'حفظ جزء عمّ', 'grade' => 'excellent'])
        ->assertCreated()->assertJsonPath('data.0.status', 'draft')->json('data.0.id');
    $cert = Certificate::find($id);
    expect($cert->certificate_no)->toStartWith('C')->and($cert->verify_token)->toHaveLength(32)
        ->and($cert->mediaIn(MediaCollection::Certificate))->toBeNull();

    // A draft is not verifiable, is editable, and renders with a stamp on demand.
    $this->getJson("/api/public/certificates/verify/{$cert->verify_token}")->assertNotFound();
    $this->putJson("/api/certificates/{$id}", ['achievement' => 'حفظ جزء تبارك'])->assertOk()->assertJsonPath('data.achievement', 'حفظ جزء تبارك');
    $this->get("/api/certificates/{$id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $this->postJson("/api/certificates/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    $cert->refresh();
    expect($cert->approved_by)->toBe($supervisor->id)
        ->and($cert->mediaIn(MediaCollection::Certificate))->not->toBeNull()
        ->and($cert->sent_at)->not->toBeNull()
        ->and(MessageLog::where('type', 'certificate_issued')->first()->body)->toContain($cert->certificate_no)->toContain('/verify/'.$cert->verify_token)
        ->and(AuditLog::where('action', 'certificate.approved')->count())->toBe(1);

    // Approved certificates are frozen and verifiable.
    $this->putJson("/api/certificates/{$id}", ['achievement' => 'x y'])->assertForbidden();
    $this->getJson("/api/public/certificates/verify/{$cert->verify_token}")->assertOk()
        ->assertJsonPath('data.valid', true)->assertJsonPath('data.recipient_name', $this->student->full_name);

    $this->postJson("/api/certificates/{$id}/revoke", [])->assertStatus(422);
    $this->postJson("/api/certificates/{$id}/revoke", ['reason' => 'Issued to the wrong student'])->assertOk()->assertJsonPath('data.status', 'revoked');
    expect($cert->fresh()->mediaIn(MediaCollection::Certificate))->toBeNull();
    $this->getJson("/api/public/certificates/verify/{$cert->verify_token}")->assertOk()->assertJsonPath('data.valid', false);
    $this->postJson("/api/certificates/{$id}/send")->assertForbidden();
});

it('lists only that student\'s certificates on the profile tab, hides drafts from guardians and marks revoked ones', function () {
    $supervisor = actingAsRole('supervisor');
    $make = fn (Student $s, string $type) => $this->postJson('/api/certificates', ['recipient_ids' => [$s->id], 'type' => $type, 'achievement' => 'جزء عمّ'])->json('data.0.id');

    $approved = $make($this->student, 'completion');
    $revoked = $make($this->student, 'excellence');
    $draft = $make($this->student, 'attendance');
    $make($this->other, 'completion');
    $this->postJson('/api/certificates/approve', ['ids' => [$approved, $revoked]])->assertJsonPath('approved', 2);
    $this->postJson("/api/certificates/{$revoked}/revoke", ['reason' => 'Duplicate'])->assertOk();

    $staff = $this->getJson("/api/certificates/recipients/student/{$this->student->id}")->assertOk();
    expect(collect($staff->json('data'))->pluck('id')->sort()->values()->all())->toBe(collect([$approved, $revoked, $draft])->sort()->values()->all())
        ->and($staff->json('summary.total'))->toBe(1)
        ->and($staff->json('summary.by_type.completion'))->toBe(1)
        ->and($staff->json('summary.drafts'))->toBe(1)
        ->and($staff->json('meta.read_only'))->toBeFalse();

    $this->getJson("/api/students/{$this->student->id}/profile")->assertOk()->assertJsonPath('data.header.certificates_count', 1);

    $guardian = guardianOf($this->student);
    $this->actingAs($guardian, 'sanctum');
    $family = $this->getJson("/api/certificates/recipients/student/{$this->student->id}")->assertOk();
    $rows = collect($family->json('data'))->keyBy('id');
    expect($rows->keys()->sort()->values()->all())->toBe(collect([$approved, $revoked])->sort()->values()->all())
        ->and($rows[$revoked]['status'])->toBe('revoked')
        ->and($rows[$revoked]['download_url'])->toBeNull()
        ->and($rows[$approved]['download_url'])->not->toBeNull()
        ->and($rows[$approved]['whatsapp_share_url'])->toStartWith('https://wa.me/')
        ->and($family->json('summary.drafts'))->toBeNull()
        ->and($family->json('meta.read_only'))->toBeTrue();

    $this->getJson("/api/certificates/{$draft}")->assertForbidden();
    $this->getJson("/api/certificates/recipients/student/{$this->other->id}")->assertForbidden();
    $this->postJson("/api/certificates/{$approved}/send")->assertForbidden();
});

it('downloads through a signed temporary URL and counts prints', function () {
    actingAsRole('supervisor');
    $id = $this->postJson('/api/certificates', ['recipient_ids' => [$this->student->id], 'type' => 'completion', 'achievement' => 'جزء عمّ'])->json('data.0.id');
    $this->postJson("/api/certificates/{$id}/approve")->assertOk();
    $row = $this->getJson("/api/certificates/{$id}")->assertOk()->json('data');

    auth()->forgetGuards();
    $this->app['auth']->guard('sanctum')->forgetUser();

    $this->get("/api/certificates/{$id}/download")->assertForbidden();                      // unsigned
    $this->get($row['download_url'].'x')->assertForbidden();                                 // tampered
    $this->get($row['download_url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($row['download_url'])->toContain('expires=')->toContain('signature=');

    $this->get($row['print_url'])->assertOk();
    expect(Certificate::find($id)->print_count)->toBe(1);

    $this->travel((int) setting('certificates.link_minutes') + 1)->minutes();
    $this->get($row['download_url'])->assertForbidden();                                     // expired
});

it('drafts a completion certificate automatically when a juz is completed, once', function () {
    $progress = app(ProgressService::class);
    // Juz 30 (An-Naba 78 → An-Nas 114) in whole surahs.
    foreach (range(78, 114) as $surah) {
        $progress->append($this->student, ['type' => 'memorized', 'surah_number' => $surah, 'from_ayah' => 1, 'to_ayah' => Quran::ayahCount($surah)], null);
    }
    $auto = Certificate::forRecipient($this->student)->where('source', 'auto_juz')->get();
    expect($auto)->toHaveCount(1)->and($auto[0]->source_id)->toBe(30)->and($auto[0]->status->value)->toBe('draft');

    // Revising or re-recording the same juz does not draft another.
    $progress->append($this->student, ['type' => 'memorized', 'surah_number' => 114, 'from_ayah' => 1, 'to_ayah' => 6], null);
    expect(Certificate::where('source', 'auto_juz')->count())->toBe(1);

    app(SettingsService::class)->set('certificates.auto_juz', false, 'certificates', 'bool');
    $progress->append($this->other, ['type' => 'memorized', 'surah_number' => 1, 'from_ayah' => 1, 'to_ayah' => 7], null);
    expect(Certificate::forRecipient($this->other)->count())->toBe(0);
});

it('lets teachers draft for their own students only, and hides the other track', function () {
    $teacher = User::factory()->create(['gender' => 'male']);
    $teacher->assignRole('teacher');
    $lesson = Lesson::factory()->create(['teacher_id' => $teacher->id]);
    LessonStudent::create(['lesson_id' => $lesson->id, 'student_id' => $this->student->id, 'joined_at' => now()->toDateString(), 'status' => 'active']);
    $this->actingAs($teacher, 'sanctum');

    $this->postJson('/api/certificates', ['recipient_ids' => [$this->other->id], 'type' => 'participation', 'achievement' => 'مسابقة'])->assertForbidden();
    $id = $this->postJson('/api/certificates', ['recipient_ids' => [$this->student->id], 'type' => 'participation', 'achievement' => 'مسابقة'])->assertCreated()->json('data.0.id');
    $this->postJson("/api/certificates/{$id}/approve")->assertForbidden();
    expect(collect($this->getJson('/api/certificates')->assertOk()->json('data'))->pluck('recipient_id')->unique()->all())->toBe([$this->student->id]);

    $girl = Student::factory()->female()->create();
    actingAsRole('supervisor', ['track' => 'male']);
    $this->postJson('/api/certificates', ['recipient_ids' => [$girl->id], 'type' => 'completion', 'achievement' => 'جزء عمّ'])->assertForbidden();
});

it('edits bilingual templates with signatures and previews them, for template managers only', function () {
    actingAsRole('supervisor');
    $this->getJson('/api/certificate-templates')->assertForbidden();

    actingAsRole('super_admin');
    $this->getJson('/api/certificate-templates')->assertOk()->assertJsonCount(6, 'data');
    $this->putJson('/api/certificate-templates/completion', [
        'title' => ['ar' => 'شهادة إتمام حفظ', 'en' => 'Memorization Certificate'],
        'body' => ['ar' => 'قد أتمّ/ت حفظ {achievement} بتقدير {grade}.', 'en' => 'has memorized {achievement} ({grade}).'],
        'signature1_name' => 'الشيخ جعفر آل شهاب', 'signature1_title' => 'مدير الهيئة',
        'ornament_level' => 'minimal', 'show_photo' => true,
    ])->assertOk()->assertJsonPath('data.ornament_level', 'minimal');
    $this->putJson('/api/certificate-templates/nope', [])->assertNotFound();

    $this->post('/api/certificate-templates/completion/signatures/1', ['image' => UploadedFile::fake()->image('sig.png', 300, 100)], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.signatures.1', fn ($v) => $v !== null);
    expect(app(FileStore::class)->exists(CertificateTemplate::where('type', 'completion')->first(), 'signature_1'))->toBeTrue()
        ->and(Media::where('collection', 'signature')->where('original_name', 'signature_1')->count())->toBe(1);
    $this->putJson('/api/certificate-templates/completion', ['title' => ['ar' => 'x'], 'body' => ['ar' => 'y'], 'ornament_level' => 'full'])
        ->assertStatus(422)->assertJsonValidationErrors(['title.en', 'body.en']);

    $this->get('/api/certificate-templates/completion/preview?locale=ar')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get('/api/certificate-templates/completion/preview?locale=en')->assertOk();

    // New drafts use the edited template; the body fills placeholders.
    $id = $this->postJson('/api/certificates', ['recipient_ids' => [$this->student->id], 'type' => 'completion', 'achievement' => 'جزء عمّ', 'grade' => 'very_good'])->json('data.0.id');
    $cert = Certificate::find($id);
    expect($cert->title)->toBe('شهادة إتمام حفظ')
        ->and(app(\Ahl\Certificates\CertificateService::class)->fill($cert->template->body('ar'), $cert, 'ar'))->toBe('قد أتمّ/ت حفظ جزء عمّ بتقدير جيد جداً.');
});

it('lists approved certificates in the printed student report', function () {
    actingAsRole('supervisor');
    $id = $this->postJson('/api/certificates', ['recipient_ids' => [$this->student->id], 'type' => 'completion', 'achievement' => 'جزء عمّ'])->json('data.0.id');
    $this->postJson("/api/certificates/{$id}/approve")->assertOk();

    $res = $this->get("/api/students/{$this->student->id}/report.pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($res->getContent())->toStartWith('%PDF');

    $this->actingAs(guardianOf($this->other), 'sanctum');
    $this->get("/api/students/{$this->student->id}/report.pdf")->assertForbidden();
});
