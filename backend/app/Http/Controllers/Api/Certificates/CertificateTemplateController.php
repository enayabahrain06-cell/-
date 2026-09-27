<?php

namespace App\Http\Controllers\Api\Certificates;

use App\Enums\CertificateType;
use App\Enums\MediaCollection;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Services\Certificates\CertificateService;
use App\Services\Media\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * @group Certificates
 * @subgroup Templates
 *
 * One bilingual template per certificate type. Body placeholders: {achievement}, {grade}, {lesson}, {date}.
 */
class CertificateTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('manageTemplates', Certificate::class);

        return response()->json(['data' => collect(CertificateType::cases())->map(fn ($t) => $this->present(CertificateTemplate::forType($t)))->values()]);
    }

    public function update(Request $request, string $type): JsonResponse
    {
        $this->authorize('manageTemplates', Certificate::class);
        $template = $this->template($type);

        $data = $request->validate([
            'title_ar' => ['required', 'string', 'max:150'],
            'title_en' => ['required', 'string', 'max:150'],
            'body_ar' => ['required', 'string', 'max:600'],
            'body_en' => ['required', 'string', 'max:600'],
            'signature1_name' => ['nullable', 'string', 'max:120'],
            'signature1_title' => ['nullable', 'string', 'max:120'],
            'signature2_name' => ['nullable', 'string', 'max:120'],
            'signature2_title' => ['nullable', 'string', 'max:120'],
            'ornament_level' => ['required', Rule::in(['full', 'minimal', 'off'])],
            'show_photo' => ['boolean'],
        ]);
        $template->update($data);

        return response()->json(['message' => __('certificates.messages.template_saved'), 'data' => $this->present($template->fresh())]);
    }

    /** Upload a signature image (PNG with a transparent background prints best) for slot 1 or 2. */
    public function storeSignature(Request $request, string $type, int $slot, MediaService $media): JsonResponse
    {
        $this->authorize('manageTemplates', Certificate::class);
        abort_unless(in_array($slot, [1, 2], true), 404);
        $request->validate(['image' => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:1024']]);
        $template = $this->template($type);

        if ($old = $template->signature($slot)) {
            $media->delete($old);
        }
        $file = $request->file('image');
        $stored = $media->storeUpload($template, MediaCollection::Signature, $file, false);
        $stored->forceFill(['original_name' => "signature_{$slot}"])->save();

        return response()->json(['message' => __('certificates.messages.signature_saved'), 'data' => $this->present($template->fresh())]);
    }

    public function destroySignature(string $type, int $slot, MediaService $media): JsonResponse
    {
        $this->authorize('manageTemplates', Certificate::class);
        $template = $this->template($type);
        if ($m = $template->signature($slot)) {
            $media->delete($m);
        }

        return response()->json(['message' => __('certificates.messages.signature_removed'), 'data' => $this->present($template->fresh())]);
    }

    /** Stream a signature image (template editor only). */
    public function signature(string $type, int $slot, MediaService $media): Response
    {
        $this->authorize('manageTemplates', Certificate::class);
        $m = $this->template($type)->signature($slot);
        abort_unless($m, 404);

        return response($media->contents($m), 200, ['Content-Type' => $m->mime, 'Cache-Control' => 'private, no-store']);
    }

    /** Sample PDF of the template (?locale=ar|en). */
    public function preview(Request $request, string $type, CertificateService $certificates): Response
    {
        $this->authorize('manageTemplates', Certificate::class);
        $locale = $request->query('locale') === 'en' ? 'en' : 'ar';

        return response($certificates->preview($this->template($type), $locale), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="template-'.$type.'-'.$locale.'.pdf"',
        ]);
    }

    private function template(string $type): CertificateTemplate
    {
        $t = CertificateType::tryFrom($type);
        abort_unless($t, 404);

        return CertificateTemplate::forType($t);
    }

    private function present(CertificateTemplate $t): array
    {
        return [
            'type' => $t->type->value,
            'type_label' => $t->type->label(),
            'title_ar' => $t->title_ar,
            'title_en' => $t->title_en,
            'body_ar' => $t->body_ar,
            'body_en' => $t->body_en,
            'signature1_name' => $t->signature1_name,
            'signature1_title' => $t->signature1_title,
            'signature2_name' => $t->signature2_name,
            'signature2_title' => $t->signature2_title,
            'ornament_level' => $t->ornament_level,
            'show_photo' => $t->show_photo,
            'signatures' => [
                1 => $t->signature(1) ? route('certificates.templates.signature', [$t->type->value, 1]) : null,
                2 => $t->signature(2) ? route('certificates.templates.signature', [$t->type->value, 2]) : null,
            ],
            'preview_url' => route('certificates.templates.preview', $t->type->value),
            'updated_at' => display_tz($t->updated_at)?->toIso8601String(),
        ];
    }
}
