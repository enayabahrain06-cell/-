<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Users & permissions
 * @subgroup Menu (القائمة)
 *
 * The staff menu's order and hidden entries, shared by everyone. The entries themselves (their exact names,
 * links and permissions) are fixed in the frontend; this only reorders sections and entries and hides some.
 * Permissions still apply on top: a visible entry shows only to users who may open it.
 */
class MenuLayoutController extends Controller
{
    private const KEY = 'menu.layout';

    // SettingsService comes in per call: a route caches its controller, so a constructor-held instance would keep a stale copy.
    public function show(Request $request, SettingsService $settings): JsonResponse
    {
        abort_unless($request->user()->can('dashboard.view') || $request->user()->can('menu.manage'), 403);

        return response()->json(['data' => $this->layout($settings)]);
    }

    public function update(Request $request, AuditLogger $audit, SettingsService $settings): JsonResponse
    {
        abort_unless($request->user()->can('menu.manage'), 403);
        $key = ['string', 'max:60', 'regex:/^[a-z0-9_]+$/'];
        $data = $request->validate([
            'sections' => ['present', 'array', 'max:30'],
            'sections.*' => $key,
            'entries' => ['present', 'array', 'max:30'],
            'entries.*' => ['array', 'max:60'],
            'entries.*.*' => $key,
            'hidden' => ['present', 'array', 'max:200'],
            'hidden.*' => $key,
        ]);
        $old = $this->layout($settings);
        $new = [
            'sections' => array_values(array_unique($data['sections'])),
            'entries' => collect($data['entries'])->map(fn ($keys) => array_values(array_unique($keys)))->all(),
            'hidden' => array_values(array_unique($data['hidden'])),
        ];
        $settings->set(self::KEY, $new, 'menu', 'json');
        $audit->record('menu.updated', null, $old, $new);

        return response()->json(['message' => __('menu.saved'), 'data' => $new]);
    }

    /** @return array{sections: list<string>, entries: array<string, list<string>>, hidden: list<string>} */
    private function layout(SettingsService $settings): array
    {
        $v = $settings->get(self::KEY);

        return [
            'sections' => array_values($v['sections'] ?? []),
            'entries' => (object) ($v['entries'] ?? []),
            'hidden' => array_values($v['hidden'] ?? []),
        ];
    }
}
