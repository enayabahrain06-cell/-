<?php

namespace Ahl\Certificates;

use Ahl\Certificates\Contracts\CertificateNotifier;
use Ahl\Certificates\Contracts\FileStore;
use Ahl\Certificates\Contracts\PdfRenderer;
use Ahl\Certificates\Contracts\Recipient;
use Ahl\Certificates\Enums\CertificateStatus;
use Ahl\Certificates\Events\CertificateApproved;
use Ahl\Certificates\Events\CertificateDrafted;
use Ahl\Certificates\Events\CertificateRevoked;
use Ahl\Certificates\Models\Certificate;
use Ahl\Certificates\Models\CertificateTemplate;
use BackedEnum;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * The certificate workflow: drafts from any source, approval (stores the final PDF and notifies the
 * recipient), revocation, signed download links, rendering and template previews.
 */
class CertificateService
{
    public const PDF_SLOT = 'pdf';

    public function __construct(
        private FileStore $files,
        private PdfRenderer $pdf,
        private CertificateNotifier $notifier,
    ) {}

    /**
     * Create a draft. Approved straight away when require_approval is off.
     *
     * @param  array{achievement?:string|null, grade?:string|BackedEnum|null, title?:string|null, context?:Model|null, source?:string|BackedEnum|null, source_id?:int|null, details?:array|null, issued_on?:string|null}  $data
     */
    public function createDraft(Model $recipient, string|BackedEnum $type, array $data = [], ?Authenticatable $by = null): Certificate
    {
        $type = Certificates::value($type);
        if (! Certificates::has('types', $type)) {
            throw ValidationException::withMessages(['type' => __('certificates::certificates.errors.unknown_type')]);
        }
        $template = Certificates::templateModel()::forType($type);
        $locale = $this->localeOf($recipient);
        $context = $data['context'] ?? null;

        $certificate = Certificates::model()::create([
            'recipient_type' => $recipient->getMorphClass(),
            'recipient_id' => $recipient->getKey(),
            'context_type' => $context?->getMorphClass(),
            'context_id' => $context?->getKey(),
            'type' => $type,
            'template_id' => $template->id,
            'title' => ($data['title'] ?? null) ?: $template->title($locale),
            'achievement' => $data['achievement'] ?? null,
            'grade' => Certificates::value($data['grade'] ?? null),
            'source' => Certificates::value($data['source'] ?? null) ?? 'manual',
            'source_id' => $data['source_id'] ?? null,
            'details' => $data['details'] ?? null,
            'issued_on' => $data['issued_on'] ?? today()->toDateString(),
            'issued_by' => $by?->getAuthIdentifier(),
            'status' => CertificateStatus::Draft,
        ]);
        $certificate->setRelation('recipient', $recipient);

        CertificateDrafted::dispatch($certificate, $by);

        if (! Certificates::setting('require_approval', true)) {
            $this->approve($certificate, $by);
        }

        return $certificate;
    }

    /** True when the recipient already has a certificate from this source (revoked ones included). */
    public function exists(Model $recipient, string|BackedEnum $source, int|string $sourceId): bool
    {
        return Certificates::model()::query()->forRecipient($recipient)->fromSource(Certificates::value($source), $sourceId)->exists();
    }

    /** Edit a draft: achievement, title, grade, context, issued_on. */
    public function update(Certificate $certificate, array $data): Certificate
    {
        $this->assertDraft($certificate);
        $certificate->fill(array_intersect_key($data, array_flip(['achievement', 'grade', 'issued_on'])));
        if (array_key_exists('context', $data)) {
            $certificate->context_type = $data['context']?->getMorphClass();
            $certificate->context_id = $data['context']?->getKey();
        }
        if (! empty($data['title'])) {
            $certificate->title = $data['title'];
        }
        $certificate->save();

        return $certificate;
    }

    public function delete(Certificate $certificate): void
    {
        $this->assertDraft($certificate);
        DB::transaction(function () use ($certificate) {
            $this->files->delete($certificate, self::PDF_SLOT);
            $certificate->delete();
        });
    }

    /** Approve a draft: issue date = today, final PDF stored, recipient notified (notify_on_approve). */
    public function approve(Certificate $certificate, ?Authenticatable $by = null): Certificate
    {
        $this->assertDraft($certificate);

        $certificate->forceFill([
            'status' => CertificateStatus::Approved,
            'approved_by' => $by?->getAuthIdentifier(),
            'approved_at' => now(),
            'issued_on' => today()->toDateString(),
        ])->save();

        $this->render($certificate);
        CertificateApproved::dispatch($certificate, $by);

        if (Certificates::setting('notify_on_approve', true) && $this->notifier->enabled()) {
            $this->send($certificate);
        }

        return $certificate;
    }

    public function revoke(Certificate $certificate, Authenticatable $by, string $reason): Certificate
    {
        if (! $certificate->isApproved()) {
            throw ValidationException::withMessages(['status' => __('certificates::certificates.errors.not_approved')]);
        }

        DB::transaction(function () use ($certificate, $by, $reason) {
            $certificate->forceFill([
                'status' => CertificateStatus::Revoked,
                'revoked_by' => $by->getAuthIdentifier(),
                'revoked_at' => now(),
                'revoke_reason' => $reason,
            ])->save();
            // The stored PDF would still look valid; later downloads are rendered fresh with a stamp.
            $this->files->delete($certificate, self::PDF_SLOT);
        });
        CertificateRevoked::dispatch($certificate, $by);

        return $certificate;
    }

    /** Notify the recipient again (the host's notifier). */
    public function send(Certificate $certificate): int
    {
        if (! $certificate->isApproved()) {
            throw ValidationException::withMessages(['status' => __('certificates::certificates.errors.not_approved')]);
        }
        $sent = $this->notifier->send($certificate, $this->verifyUrl($certificate));
        if ($sent) {
            $certificate->forceFill(['sent_at' => now()])->save();
        }

        return $sent;
    }

    public function canSend(): bool
    {
        return $this->notifier->enabled();
    }

    public function verifyUrl(Certificate $certificate): string
    {
        return Certificates::host()->verifyUrl($certificate);
    }

    /** Signed, short-lived download link (no login needed while it lasts). */
    public function downloadUrl(Certificate $certificate, bool $print = false, bool $view = false): string
    {
        return URL::temporarySignedRoute('certificates.download', now()->addMinutes((int) Certificates::setting('link_minutes', 30)),
            ['certificate' => $certificate->getKey()] + ($print ? ['print' => 1] : []) + ($view ? ['view' => 1] : []));
    }

    /** Stored PDF for approved certificates; drafts and revoked ones are rendered on demand with a stamp. */
    public function pdfContents(Certificate $certificate): string
    {
        if ($certificate->isApproved() && ($file = $this->files->get($certificate, self::PDF_SLOT))) {
            return $file['contents'];
        }

        return $this->render($certificate);
    }

    /** Render the PDF. Approved certificates are stored in the FileStore. */
    public function render(Certificate $certificate): string
    {
        $certificate->loadMissing('recipient', 'context', 'template');
        $template = $certificate->template ?? Certificates::templateModel()::forType($certificate->type);
        $recipient = $certificate->recipient;
        $locale = $this->localeOf($recipient);

        $bytes = $this->renderView($template, $locale, [
            'name' => $recipient instanceof Recipient ? $recipient->certificateName() : (string) ($recipient?->getAttribute('name') ?? ''),
            'title' => $certificate->title ?: $template->title($locale),
            'body' => $this->fill($template->body($locale), $certificate, $locale),
            'grade' => $certificate->gradeLabel($locale),
            'date' => $certificate->issued_on ?? today(),
            'certificate_no' => $certificate->certificate_no,
            'qr' => $certificate->isApproved() ? $this->qrDataUri($this->verifyUrl($certificate)) : null,
            'stamp' => match ($certificate->status) {
                CertificateStatus::Draft => __('certificates::certificates.draft_mark', [], $locale),
                CertificateStatus::Revoked => __('certificates::certificates.revoked_mark', [], $locale),
                default => null,
            },
            'photo' => $template->show_photo && $recipient instanceof Recipient ? $recipient->certificatePhoto() : null,
        ], $certificate);

        if ($certificate->isApproved()) {
            $this->files->put($certificate, self::PDF_SLOT, $bytes, 'application/pdf', $certificate->certificate_no.'.pdf');
        }

        return $bytes;
    }

    /** A sample PDF of a template in one language, for the template editor. */
    public function preview(CertificateTemplate $template, string $locale): string
    {
        $locale = Certificates::locale($locale);
        $grade = Certificates::keys('grades')[0] ?? null;
        $sample = Certificates::model()::make(['achievement' => __('certificates::certificates.sample_achievement', [], $locale), 'grade' => $grade, 'issued_on' => today(), 'verify_token' => 'sample']);

        return $this->renderView($template, $locale, [
            'name' => __('certificates::certificates.sample_name', [], $locale),
            'title' => $template->title($locale),
            'body' => $this->fill($template->body($locale), $sample, $locale, __('certificates::certificates.sample_name', [], $locale)),
            'grade' => Certificates::label('grades', $grade, $locale),
            'date' => today(),
            'certificate_no' => config('certificates.number.prefix', 'C').now()->format('y').str_repeat('0', (int) config('certificates.number.digits', 5)),
            'qr' => $this->qrDataUri($this->verifyUrl($sample)),
            'stamp' => null,
            'photo' => null,
        ], null);
    }

    public function qrDataUri(string $text): string
    {
        return 'data:image/png;base64,'.base64_encode((new Writer(new GDLibRenderer(220, 1)))->writeString($text));
    }

    /** Placeholder tokens a template body may use. */
    public function placeholderKeys(): array
    {
        return array_values(array_unique([...['name', 'achievement', 'grade', 'context', 'date'], ...Certificates::host()->placeholderKeys()]));
    }

    /** Replace {name}, {achievement}, {grade}, {context}, {date} and the host's extra placeholders. */
    public function fill(string $text, Certificate $certificate, string $locale, ?string $name = null): string
    {
        $recipient = $certificate->relationLoaded('recipient') || $certificate->recipient_id ? $certificate->recipient : null;
        $context = $certificate->context_id ? $certificate->context : null;
        $values = [
            'name' => $name ?? ($recipient instanceof Recipient ? $recipient->certificateName() : ''),
            'achievement' => (string) $certificate->achievement,
            'grade' => (string) $certificate->gradeLabel($locale),
            'context' => $context ? (string) (Certificates::host()->presentContext($context)['name'] ?? '') : '',
            'date' => (string) $certificate->issued_on?->toDateString(),
        ];
        foreach (Certificates::host()->placeholders($certificate, $locale) as $key => $value) {
            $values[$key] = (string) $value;
        }

        return trim(preg_replace('/\s{2,}/u', ' ', strtr($text, collect($values)->mapWithKeys(fn ($v, $k) => ['{'.$k.'}' => $v])->all())));
    }

    public function localeOf(?Model $recipient): string
    {
        return Certificates::locale($recipient instanceof Recipient ? $recipient->certificateLocale() : null);
    }

    /** Signature image as a data: URI, or null. */
    public function signatureDataUri(CertificateTemplate $template, int $slot): ?string
    {
        $file = $this->files->get($template, "signature_{$slot}");

        return $file ? 'data:'.$file['mime'].';base64,'.base64_encode($file['contents']) : null;
    }

    private function renderView(CertificateTemplate $template, string $locale, array $cert, ?Certificate $certificate): string
    {
        /** @var CarbonInterface $date */
        $date = $cert['date'];
        $host = Certificates::host();

        $data = $host->viewData([
            'locale' => $locale,
            'rtl' => in_array($locale, ['ar', 'fa', 'he', 'ur'], true),
            'ornament' => in_array($template->ornament_level, ['full', 'minimal', 'off'], true) ? $template->ornament_level : 'full',
            'issuer' => $host->issuer($locale),
            'date' => $date->format('Y/m/d'),
            'date_secondary' => $host->secondaryDate($date, $locale),
            'signatures' => collect(CertificateTemplate::SIGNATURE_SLOTS)->map(fn ($slot) => [
                'name' => $template->{"signature{$slot}_name"},
                'title' => $template->{"signature{$slot}_title"},
                'image' => $this->signatureDataUri($template, $slot),
            ])->filter(fn ($s) => $s['name'] || $s['title'] || $s['image'])->values()->all(),
            'cert' => $cert,
        ], $certificate, $locale);

        return $this->pdf->render(config('certificates.pdf.view', 'certificates::pdf.certificate'), $data,
            config('certificates.pdf.orientation', 'landscape'), config('certificates.pdf.paper', 'a4'));
    }

    private function assertDraft(Certificate $certificate): void
    {
        if (! $certificate->isDraft()) {
            throw ValidationException::withMessages(['status' => __('certificates::certificates.errors.not_draft')]);
        }
    }
}
