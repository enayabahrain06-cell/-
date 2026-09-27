<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\MediaCollection;
use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\Media\MediaService;
use App\Services\Settings\SettingsRegistry;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * @group Settings
 *
 * System settings for the Super Admin (settings.manage). Which keys are editable, and how each is
 * validated, lives in SettingsRegistry; seeded keys it does not know are listed read-only.
 */
class SettingsController extends Controller
{
    private const LOGO_KEY = 'authority.logo_media_id';

    public function __construct(private SettingsService $settings) {}

    /** All settings grouped for the settings screen, with input metadata and the logo URL. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        return response()->json(['data' => $this->payload()]);
    }

    /**
     * Partial update. Body: {"settings": {"authority.name_ar": "…", "locale.show_hijri": true}}.
     * Unknown or read-only keys are rejected. Errors are keyed by setting key.
     */
    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        $input = $request->input('settings');
        if (! is_array($input) || $input === []) {
            throw ValidationException::withMessages(['settings' => __('settings.errors.empty')]);
        }

        // Keys contain dots, so validate under safe aliases and map the errors back.
        $data = $rules = $names = $errors = [];
        foreach ($input as $key => $value) {
            $key = (string) $key;
            if (! SettingsRegistry::editable($key)) {
                $errors[$key] = [__('settings.errors.not_editable', ['key' => $key])];

                continue;
            }
            $alias = 'k'.count($data);
            $data[$alias] = $value;
            $rules[$alias] = SettingsRegistry::rules($key);
            $names[$alias] = $key;
        }

        // Label keys contain dots, so read the array rather than __("settings.labels.<key>") (dots mean nesting).
        $labels = (array) __('settings.labels');
        $validator = Validator::make($data, $rules, [], array_map(fn ($k) => $labels[$k] ?? $k, $names));
        foreach ($validator->errors()->messages() as $alias => $messages) {
            $errors[$names[$alias]] = $messages;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $current = $this->settings->all();
        $old = $new = [];
        DB::transaction(function () use ($data, $names, $current, &$old, &$new) {
            foreach ($data as $alias => $value) {
                $key = $names[$alias];
                $def = SettingsRegistry::get($key);
                $value = SettingsRegistry::normalise($key, $value);
                if (($current[$key] ?? null) === $value) {
                    continue;
                }
                $old[$key] = $current[$key] ?? null;
                $new[$key] = $value;
                $this->settings->set($key, $value, $def['group'], $def['type']);
            }
        });

        if ($new) {
            $audit->record('settings.updated', 'settings', $old, $new);
        }

        return response()->json(['message' => __('settings.saved'), 'changed' => array_keys($new), 'data' => $this->payload()]);
    }

    /** Upload or replace the authority logo (PNG, JPEG or WebP, up to 2 MB). */
    public function uploadLogo(Request $request, MediaService $media, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);
        $request->validate(['logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000']]);

        $old = $this->logoId();
        $row = $this->logoRow();
        $file = $media->storeUpload($row, MediaCollection::Logo, $request->file('logo'));
        $this->settings->set(self::LOGO_KEY, $file->id, 'authority', 'int');
        $audit->record('settings.logo_updated', 'settings', [self::LOGO_KEY => $old], [self::LOGO_KEY => $file->id]);

        return response()->json(['message' => __('settings.logo_saved'), 'data' => $this->payload()]);
    }

    public function deleteLogo(Request $request, MediaService $media, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        $old = $this->logoId();
        $media->deleteCollection($this->logoRow(), MediaCollection::Logo);
        $this->settings->set(self::LOGO_KEY, null, 'authority', 'int');
        if ($old) {
            $audit->record('settings.logo_removed', 'settings', [self::LOGO_KEY => $old], [self::LOGO_KEY => null]);
        }

        return response()->json(['message' => __('settings.logo_removed'), 'data' => $this->payload()]);
    }

    /**
     * The authority logo, public so the login page, registration page and printouts can show it.
     *
     * @unauthenticated
     */
    public function logo(MediaService $media): Response
    {
        $file = ($id = $this->logoId()) ? Media::where('id', $id)->where('collection', MediaCollection::Logo->value)->first() : null;
        $bytes = $file ? $media->contents($file) : null;
        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type' => $file->mime,
            'Content-Length' => strlen($bytes),
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function logoId(): ?int
    {
        return ((int) $this->settings->get(self::LOGO_KEY)) ?: null;
    }

    /** The settings row the logo file is attached to (media is polymorphic). */
    private function logoRow(): Setting
    {
        return Setting::firstOrCreate(['key' => self::LOGO_KEY], ['value' => '', 'group' => 'authority', 'type' => 'int']);
    }

    private function payload(): array
    {
        $values = $this->settings->all();
        $types = Setting::query()->pluck('type', 'key')->all();
        $groups = [];

        // Registry order first, then any seeded key the registry does not know (read-only).
        $keys = array_unique([...array_keys(SettingsRegistry::definitions()), ...array_keys($values)]);
        foreach ($keys as $key) {
            if (! array_key_exists($key, $values) && ! SettingsRegistry::get($key)) {
                continue;
            }
            $def = SettingsRegistry::get($key);
            $group = $def['group'] ?? (Setting::where('key', $key)->value('group') ?? 'general');
            $item = [
                'key' => $key,
                'type' => $def['type'] ?? ($types[$key] ?? 'string'),
                'value' => $values[$key] ?? null,
                'editable' => SettingsRegistry::editable($key),
                'known' => $def !== null,
            ];
            if ($key === 'locale.timezone') {
                $item['options'] = \DateTimeZone::listIdentifiers();
            } elseif (isset($def['options'])) {
                $item['options'] = $def['options'];
            }
            foreach (['min', 'max'] as $bound) {
                if (isset($def[$bound])) {
                    $item[$bound] = $def[$bound];
                }
            }
            $groups[$group][] = $item;
        }

        $logoId = $this->logoId();

        return [
            'groups' => collect($groups)->map(fn ($items, $key) => ['key' => $key, 'settings' => $items])->values()->all(),
            'logo_url' => $logoId ? url('/api/public/logo').'?v='.$logoId : null,
        ];
    }
}
