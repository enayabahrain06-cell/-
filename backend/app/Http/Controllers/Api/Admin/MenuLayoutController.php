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
 *
 * nav_v2 (docs/07-NAV-V2.md) adds the tab order per section (`tabs`) and the runtime switch between the old menu and
 * nav_v2 (`nav_v2`, off by default). Both are optional on save: a client that does not send them keeps the saved ones.
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
            'tabs' => ['sometimes', 'array', 'max:30'],
            'tabs.*' => ['array', 'max:30'],
            'tabs.*.*' => $key,
            'nav_v2' => ['sometimes', 'boolean'],
        ]);
        $old = $this->layout($settings);
        $new = [
            'sections' => array_values(array_unique($data['sections'])),
            'entries' => collect($data['entries'])->map(fn ($keys) => array_values(array_unique($keys)))->all(),
            'hidden' => array_values(array_unique($data['hidden'])),
            'tabs' => array_key_exists('tabs', $data)
                ? collect($data['tabs'])->map(fn ($keys) => array_values(array_unique($keys)))->all()
                : (array) $old['tabs'],
            'nav_v2' => array_key_exists('nav_v2', $data) ? (bool) $data['nav_v2'] : $old['nav_v2'],
        ];
        $settings->set(self::KEY, $new, 'menu', 'json');
        $audit->record('menu.updated', null, $old, $new);

        return response()->json(['message' => __('menu.saved'), 'data' => $this->layout($settings)]);
    }

    /** @return array{sections: list<string>, entries: object, hidden: list<string>, tabs: object, nav_v2: bool} */
    private function layout(SettingsService $settings): array
    {
        $v = $settings->get(self::KEY);

        return [
            'sections' => array_values($v['sections'] ?? []),
            'entries' => (object) ($v['entries'] ?? []),
            'hidden' => array_values($v['hidden'] ?? []),
            'tabs' => (object) ($v['tabs'] ?? []),
            'nav_v2' => (bool) ($v['nav_v2'] ?? false),
        ];
    }
}
