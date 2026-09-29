<?php

namespace Ahl\Certificates\Http\Controllers;

use Ahl\Certificates\Certificates;
use Ahl\Certificates\CertificateService;
use Ahl\Certificates\Contracts\FileStore;
use Ahl\Certificates\Models\CertificateTemplate;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * @group Certificates
 * @subgroup Templates
 *
 * One template per certificate type, with a title and body per locale (certificates.locales).
 * Body placeholders: see GET /certificates/options → placeholders.
 */
class TemplateController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private FileStore $files) {}

    public function index(): JsonResponse
    {
        $this->authorize('manageTemplates', Certificates::model());

        return response()->json(['data' => collect(Certificates::keys('types'))->map(fn ($t) => $this->present(Certificates::templateModel()::forType($t)))->values()]);
    }

    public function update(Request $request, string $type): JsonResponse
    {
        $this->authorize('manageTemplates', Certificates::model());
        $template = $this->template($type);

        $rules = [
            'title' => ['required', 'array'],
            'body' => ['required', 'array'],
            'signature1_name' => ['nullable', 'string', 'max:120'],
            'signature1_title' => ['nullable', 'string', 'max:120'],
            'signature2_name' => ['nullable', 'string', 'max:120'],
            'signature2_title' => ['nullable', 'string', 'max:120'],
            'ornament_level' => ['required', Rule::in(['full', 'minimal', 'off'])],
            'show_photo' => ['boolean'],
        ];
        foreach (Certificates::locales() as $locale) {
            $rules["title.{$locale}"] = ['required', 'string', 'max:150'];
            $rules["body.{$locale}"] = ['required', 'string', 'max:600'];
        }
        $data = $request->validate($rules);
        $data['title'] = array_intersect_key($data['title'], array_flip(Certificates::locales()));
        $data['body'] = array_intersect_key($data['body'], array_flip(Certificates::locales()));
        $template->update($data);

        return response()->json(['message' => __('certificates::certificates.messages.template_saved'), 'data' => $this->present($template->fresh())]);
    }

    /** Upload a signature image (PNG with a transparent background prints best) for slot 1 or 2. */
    public function storeSignature(Request $request, string $type, int $slot): JsonResponse
    {
        $this->authorize('manageTemplates', Certificates::model());
        abort_unless(in_array($slot, CertificateTemplate::SIGNATURE_SLOTS, true), 404);
        $request->validate(['image' => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:1024']]);
        $template = $this->template($type);
        $file = $request->file('image');

        $this->files->delete($template, "signature_{$slot}");
        $this->files->put($template, "signature_{$slot}", (string) $file->get(), $file->getMimeType() ?: 'image/png', "signature_{$slot}");

        return response()->json(['message' => __('certificates::certificates.messages.signature_saved'), 'data' => $this->present($template->fresh())]);
    }

    public function destroySignature(string $type, int $slot): JsonResponse
    {
        $this->authorize('manageTemplates', Certificates::model());
        abort_unless(in_array($slot, CertificateTemplate::SIGNATURE_SLOTS, true), 404);
        $this->files->delete($this->template($type), "signature_{$slot}");

        return response()->json(['message' => __('certificates::certificates.messages.signature_removed'), 'data' => $this->present($this->template($type))]);
    }

    /** Stream a signature image (template editor only). */
    public function signature(string $type, int $slot): Response
    {
        $this->authorize('manageTemplates', Certificates::model());
        $file = $this->files->get($this->template($type), "signature_{$slot}");
        abort_unless($file, 404);

        return response($file['contents'], 200, ['Content-Type' => $file['mime'], 'Cache-Control' => 'private, no-store']);
    }

    /** Sample PDF of the template (?locale=). */
    public function preview(Request $request, string $type, CertificateService $certificates): Response
    {
        $this->authorize('manageTemplates', Certificates::model());
        $locale = Certificates::locale($request->query('locale'));

        return response($certificates->preview($this->template($type), $locale), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="template-'.$type.'-'.$locale.'.pdf"',
        ]);
    }

    private function template(string $type): CertificateTemplate
    {
        abort_unless(Certificates::has('types', $type), 404);

        return Certificates::templateModel()::forType($type);
    }

    private function present(CertificateTemplate $t): array
    {
        $signatures = [];
        foreach (CertificateTemplate::SIGNATURE_SLOTS as $slot) {
            $signatures[$slot] = $this->files->exists($t, "signature_{$slot}") ? route('certificates.templates.signature', [$t->type, $slot]) : null;
        }

        return [
            'type' => $t->type,
            'type_label' => Certificates::label('types', $t->type),
            'title' => (object) collect(Certificates::locales())->mapWithKeys(fn ($l) => [$l => $t->title($l)])->all(),
            'body' => (object) collect(Certificates::locales())->mapWithKeys(fn ($l) => [$l => $t->body($l)])->all(),
            'signature1_name' => $t->signature1_name,
            'signature1_title' => $t->signature1_title,
            'signature2_name' => $t->signature2_name,
            'signature2_title' => $t->signature2_title,
            'ornament_level' => $t->ornament_level,
            'show_photo' => (bool) $t->show_photo,
            'signatures' => $signatures,
            'preview_url' => route('certificates.templates.preview', $t->type),
            'updated_at' => Certificates::host()->displayTime($t->updated_at)?->toIso8601String(),
        ];
    }
}
