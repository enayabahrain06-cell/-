<?php

namespace App\Http\Controllers\Api\Messages;

use App\Http\Controllers\Controller;
use App\Http\Requests\Messages\UpdateTemplateRequest;
use App\Http\Resources\MessageTemplateResource;
use App\Models\MessageTemplate;
use App\Services\AuditLogger;
use App\Services\Messaging\TemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Messages — templates
 */
class TemplateController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('messages.view') || $request->user()->can('messages.manage'), 403);

        return MessageTemplateResource::collection(MessageTemplate::orderBy('key')->get());
    }

    public function update(UpdateTemplateRequest $request, MessageTemplate $template, AuditLogger $audit): MessageTemplateResource
    {
        $old = $template->only(['name_ar', 'name_en', 'body_ar', 'body_en', 'is_active']);
        $template->update($request->validated());
        $audit->record('message_template.updated', $template, $old, $template->only(array_keys($old)));

        return new MessageTemplateResource($template->fresh());
    }

    /** Render the template with sample values. */
    public function preview(Request $request, MessageTemplate $template, TemplateRenderer $renderer): JsonResponse
    {
        abort_unless($request->user()->can('messages.view') || $request->user()->can('messages.manage'), 403);

        $locale = $request->string('locale', app()->getLocale())->toString() === 'en' ? 'en' : 'ar';
        $body = $request->filled('body') ? (string) $request->input('body') : $template->bodyFor($locale);

        return response()->json([
            'locale' => $locale,
            'body' => $renderer->interpolate($body, self::samples($locale), $locale),
        ]);
    }

    public static function samples(string $locale): array
    {
        return $locale === 'en'
            ? ['name' => 'Hussain Ali Al-Mahroos', 'lesson' => 'Imam Nafi circle', 'time' => '4:00 PM', 'assignment' => 'Surah Al-Mulk 1–10', 'date' => '2026-10-01', 'teacher' => 'Sh. Jaafar Al Shehab', 'location' => 'Sar Grand Matam Hall', 'map_link' => 'https://maps.google.com/?q=26.2,50.5', 'request_no' => 'R26100001', 'score' => '9/10', 'balance' => 'BHD 15.000', 'amount' => 'BHD 20.000', 'invoice_no' => 'INV260900001', 'code' => '123456', 'minutes' => '5', 'package' => 'Memorization Package', 'status' => 'Passed', 'link' => 'https://example.bh/track', 'body' => 'Sample text', 'title' => 'Certificate of Memorization', 'achievement' => 'Completed Juz Amma with Excellent', 'certificate_no' => 'C2600001', 'guardian_name' => 'Ali Hassan Al-Mahroos', 'next_date' => 'Wednesday 2026-10-03', 'absence_count' => '3', 'supervisor_phone' => '+97317000000']
            : ['name' => 'حسين علي المحروس', 'lesson' => 'حلقة الإمام نافع', 'time' => '٤:٠٠ م', 'assignment' => 'سورة الملك ١–١٠', 'date' => '٢٠٢٦-١٠-٠١', 'teacher' => 'الشيخ جعفر آل شهاب', 'location' => 'قاعة مأتم سار الكبير', 'map_link' => 'https://maps.google.com/?q=26.2,50.5', 'request_no' => 'R26100001', 'score' => '٩/١٠', 'balance' => '١٥٫٠٠٠ د.ب', 'amount' => '٢٠٫٠٠٠ د.ب', 'invoice_no' => 'INV260900001', 'code' => '123456', 'minutes' => '5', 'package' => 'باقة الحفظ', 'status' => 'ناجح', 'link' => 'https://example.bh/track', 'body' => 'نص تجريبي', 'title' => 'شهادة إتمام حفظ', 'achievement' => 'أتمّ حفظ جزء عمّ كاملاً بتقدير ممتاز', 'certificate_no' => 'C2600001', 'guardian_name' => 'علي حسن المحروس', 'next_date' => 'الأربعاء ٢٠٢٦-١٠-٠٣', 'absence_count' => '٣', 'supervisor_phone' => '+97317000000'];
    }
}
