<?php

namespace App\Http\Controllers\Api\Gallery;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Album;
use App\Models\AlbumPhoto;
use App\Models\Competition;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Gallery\GalleryAccess;
use App\Services\Gallery\GalleryService;
use App\Services\Gallery\VideoSupport;
use App\Support\TeacherScope;
use App\Support\TermScope;
use App\Support\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @group Photo gallery
 *
 * معرض الصور (Phase 8) for staff. Albums of the requested term (?term_id=, or all), in the user's gender track.
 * Files are never in these responses: the screens load them from gallery/photos/{photo}/{variant}.
 */
class GalleryController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /** What the screens pick from: links the user may attach, limits, and whether videos are on. */
    public function options(Request $request, VideoSupport $video): JsonResponse
    {
        $user = $request->user();
        abort_unless(GalleryAccess::staff($user), 403);
        $term = TermScope::fromRequest($request);
        $termId = is_int($term) ? $term : AcademicTerm::current()?->id;
        $manager = GalleryAccess::manager($user);
        $locale = app()->getLocale();

        $classes = Lesson::query()->tap(fn ($q) => TermScope::via($q, $termId))->tap(fn ($q) => Track::scope($q, $user))
            ->when(! $manager, fn ($q) => $q->whereIn('lessons.id', TeacherScope::lessonIds($user)))
            ->orderBy('name')->get(['lessons.id', 'lessons.name', 'lessons.gender', 'lessons.level_id']);

        return response()->json([
            'classes' => $classes->map(fn (Lesson $l) => ['id' => $l->id, 'name' => $l->name, 'gender' => $l->gender?->value, 'level_id' => $l->level_id]),
            'levels' => $manager ? Level::query()->ordered()->get()->map(fn (Level $l) => ['id' => $l->id, 'name' => $l->name($locale)]) : [],
            'competitions' => $manager ? Track::scope(Competition::query(), $user)->orderByDesc('id')->limit(200)->get()
                ->map(fn (Competition $c) => ['id' => $c->id, 'name' => $c->name($locale), 'gender' => $c->gender]) : [],
            'link_types' => array_keys(Album::LINKS),
            'video_enabled' => $video->enabled(),
            'max_upload_mb' => (int) config('ahl.gallery.max_upload_mb', 15),
            'video_max_mb' => (int) config('ahl.gallery.video_max_mb', 50),
            'video_max_seconds' => (int) config('ahl.gallery.video_max_seconds', 60),
            'track' => Track::genderFor($user)?->value,
            'can_manage' => $manager,
            'can_create' => $manager || $user->can('gallery.upload'),
        ]);
    }

    /** Albums, newest first. Filters: term_id, link_type + link_id, visibility, q. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(GalleryAccess::staff($user), 403);
        $f = $request->validate([
            'link_type' => ['nullable', Rule::in(array_keys(Album::LINKS))], 'link_id' => ['nullable', 'integer'],
            'visibility' => ['nullable', Rule::in(Album::VISIBILITIES)], 'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $term = TermScope::fromRequest($request);

        $page = GalleryAccess::staffAlbums($user)->with(['cover', 'term', 'creator:id,name'])->withCount('photos')
            ->when(is_int($term), fn ($q) => $q->where('academic_term_id', $term))
            ->when($f['link_type'] ?? null, fn ($q, $v) => $q->where('link_type', $v))
            ->when($f['link_id'] ?? null, fn ($q, $v) => $q->where('link_id', $v))
            ->when($f['visibility'] ?? null, fn ($q, $v) => $q->where('visibility', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where('title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $v).'%'))
            ->orderByDesc('album_date')->orderByDesc('id')
            ->paginate((int) ($f['per_page'] ?? 24));

        return response()->json([
            'data' => collect($page->items())->map(fn (Album $a) => self::present($a, $user)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, Album $album): JsonResponse
    {
        $user = $request->user();
        abort_unless(GalleryAccess::staff($user) && GalleryAccess::canView($user, $album), 403);
        $album->load(['cover', 'term', 'creator:id,name', 'photos.uploader:id,name'])->loadCount('photos');
        $album->photos->each->setRelation('album', $album);

        return response()->json(['data' => self::present($album, $user) + [
            'photos' => $album->photos->map(fn (AlbumPhoto $p) => self::photo($p, $user)),
            'consent_withheld' => self::consentList($album),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(GalleryAccess::manager($user) || $user->can('gallery.upload'), 403);
        $data = $this->validated($request, true);
        abort_unless(GalleryAccess::canCreateFor($user, $data['link_type'] ?? null, $data['link_id'] ?? null), 403);
        [$gender, $termId] = $this->derive($data, $user);

        $album = Album::create([
            'title' => $data['title'], 'description' => $data['description'] ?? null, 'album_date' => $data['album_date'],
            'academic_term_id' => $termId, 'gender' => $gender,
            'link_type' => $data['link_type'] ?? null, 'link_id' => isset($data['link_type']) ? ($data['link_id'] ?? null) : null,
            'visibility' => 'staff', 'allow_download' => false, 'created_by' => $user->id,
        ]);
        $this->audit->record('gallery.album_created', $album, [], $album->only(['title', 'album_date', 'link_type', 'link_id', 'gender']));

        return response()->json(['data' => self::present($album->loadCount('photos'), $user)], 201);
    }

    /** Title, description, date, link and cover. Visibility and السماح بالتحميل go through share(). */
    public function update(Request $request, Album $album): JsonResponse
    {
        $user = $request->user();
        abort_unless(GalleryAccess::canEdit($user, $album), 403);
        $data = $this->validated($request, false);

        if (array_key_exists('link_type', $data) || array_key_exists('link_id', $data)) {
            $type = $data['link_type'] ?? null;
            $id = $type ? ($data['link_id'] ?? null) : null;
            abort_unless(GalleryAccess::canCreateFor($user, $type, $id), 403);
            [$gender, $termId] = $this->derive(['link_type' => $type, 'link_id' => $id, 'gender' => $data['gender'] ?? $album->gender, 'academic_term_id' => $data['academic_term_id'] ?? $album->academic_term_id], $user);
            $data = array_merge($data, ['link_type' => $type, 'link_id' => $id, 'gender' => $gender, 'academic_term_id' => $termId]);
        } elseif (isset($data['gender'])) {
            abort_if($album->link_type === 'lesson' || $album->link_type === 'competition', 422, __('gallery.errors.gender_from_link'));
            abort_unless(Track::allows($user, $data['gender']), 403);
        }
        if (array_key_exists('cover_photo_id', $data) && $data['cover_photo_id'] !== null) {
            abort_unless(AlbumPhoto::where('album_id', $album->id)->whereKey($data['cover_photo_id'])->exists(), 422, __('gallery.errors.cover'));
        }

        $keys = array_keys($data);
        $old = $album->only($keys);
        $album->update($data);
        $this->audit->record('gallery.album_updated', $album, $old, $album->only($keys));

        return response()->json(['data' => self::present($album->fresh(['cover', 'term', 'creator:id,name'])->loadCount('photos'), $user)]);
    }

    /** Visibility (staff / linked / all_guardians), السماح بالتحميل, and optionally WhatsApp the guardians it reaches. */
    public function share(Request $request, Album $album, GalleryService $gallery): JsonResponse
    {
        $user = $request->user();
        abort_unless(GalleryAccess::canShare($user, $album), 403);
        $data = $request->validate([
            'visibility' => ['required', Rule::in(Album::VISIBILITIES)],
            'allow_download' => ['required', 'boolean'],
            'notify' => ['boolean'],
        ]);
        if ($data['visibility'] === 'linked' && ! $album->link_type) {
            throw ValidationException::withMessages(['visibility' => __('gallery.errors.linked_needs_link')]);
        }
        $sent = $gallery->share($album, $data['visibility'], (bool) $data['allow_download'], (bool) ($data['notify'] ?? false));

        return response()->json(['data' => self::present($album->fresh(['cover', 'term', 'creator:id,name'])->loadCount('photos'), $user), 'notified' => $sent]);
    }

    public function destroy(Request $request, Album $album, GalleryService $gallery): JsonResponse
    {
        abort_unless(GalleryAccess::canDelete($request->user(), $album), 403);
        $gallery->deleteAlbum($album);

        return response()->json(['message' => __('gallery.deleted')]);
    }

    public function reorder(Request $request, Album $album, GalleryService $gallery): JsonResponse
    {
        abort_unless(GalleryAccess::canEdit($request->user(), $album), 403);
        $data = $request->validate(['ids' => ['required', 'array', 'max:2000'], 'ids.*' => ['integer']]);
        $gallery->reorder($album, array_map('intval', $data['ids']));

        return response()->json(['data' => $album->photos()->pluck('id')]);
    }

    /** The students of the album's link who withheld photo consent, for the warning before uploading. */
    public function consent(Request $request, Album $album): JsonResponse
    {
        $user = $request->user();
        abort_unless(GalleryAccess::staff($user) && GalleryAccess::canView($user, $album), 403);

        return response()->json(['data' => self::consentList($album)]);
    }

    // -----------------------------------------------------------------------------------------------------------

    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'title' => [$req, 'string', 'min:2', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'album_date' => [$req, 'date'],
            'academic_term_id' => ['nullable', 'integer', 'exists:academic_terms,id'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'mixed'])],
            'link_type' => ['nullable', Rule::in(array_keys(Album::LINKS))],
            'link_id' => ['nullable', 'required_with:link_type', 'integer'],
            'cover_photo_id' => $creating ? ['prohibited'] : ['nullable', 'integer'],
        ]);
    }

    /**
     * Gender and term follow the linked class or competition; a level or no link takes the chosen gender (a single
     * track user's own by default) and the chosen term (the current one by default).
     *
     * @return array{0: string, 1: ?int}
     */
    private function derive(array $data, User $user): array
    {
        $termId = $data['academic_term_id'] ?? AcademicTerm::current()?->id;
        $gender = $data['gender'] ?? Track::genderFor($user)?->value;
        $type = $data['link_type'] ?? null;

        if ($type) {
            $class = Album::LINKS[$type];
            $linked = $class::find($data['link_id'] ?? 0);
            if (! $linked) {
                throw ValidationException::withMessages(['link_id' => __('gallery.errors.link_missing')]);
            }
            if ($linked instanceof Lesson) {
                $gender = $linked->gender?->value;
                $termId = $linked->package?->academic_term_id ?? $termId;
            } elseif ($linked instanceof Competition) {
                $gender = $linked->gender;
            }
        }
        if (! $gender) {
            throw ValidationException::withMessages(['gender' => __('gallery.errors.gender_required')]);
        }
        abort_unless(Track::allows($user, $gender), 403);

        return [$gender, $termId];
    }

    /** Album card for staff and the portal ($user decides the "can" flags). */
    public static function present(Album $album, User $user): array
    {
        $locale = app()->getLocale();
        $linked = $album->linked();
        $cover = $album->relationLoaded('cover') && $album->cover ? $album->cover : $album->coverPhoto();
        $staff = GalleryAccess::staff($user);

        return [
            'id' => $album->id,
            'title' => $album->title,
            'description' => $album->description,
            'album_date' => $album->album_date?->toDateString(),
            'term' => $album->academic_term_id ? ['id' => $album->academic_term_id, 'name' => AcademicTerm::nameFor($album->academic_term_id)] : null,
            'gender' => $album->gender,
            'link' => $album->link_type ? [
                'type' => $album->link_type, 'id' => $album->link_id,
                'name' => $linked ? (method_exists($linked, 'name') ? $linked->name($locale) : $linked->name) : null,
            ] : null,
            'cover' => $cover ? ['id' => $cover->id, 'kind' => $cover->kind] : null,
            'cover_photo_id' => $album->cover_photo_id,
            'photos_count' => (int) ($album->photos_count ?? $album->photos()->count()),
            'visibility' => $staff ? $album->visibility : null,
            'allow_download' => $album->allow_download,
            'shared_at' => $staff ? $album->shared_at?->toIso8601String() : null,
            'created_by' => $staff && $album->relationLoaded('creator') && $album->creator ? ['id' => $album->creator->id, 'name' => $album->creator->name] : null,
            'can' => [
                'upload' => GalleryAccess::canUpload($user, $album),
                'edit' => GalleryAccess::canEdit($user, $album),
                'share' => GalleryAccess::canShare($user, $album),
                'delete' => GalleryAccess::canDelete($user, $album),
                'download' => GalleryAccess::canDownload($user, $album),
            ],
        ];
    }

    public static function photo(AlbumPhoto $p, User $user): array
    {
        $staff = GalleryAccess::staff($user);

        return [
            'id' => $p->id,
            'kind' => $p->kind,
            'caption' => $p->caption,
            'position' => $p->position,
            'width' => $p->width,
            'height' => $p->height,
            'duration_seconds' => $p->duration_seconds,
            'uploaded_by' => $staff && $p->uploader ? ['id' => $p->uploader->id, 'name' => $p->uploader->name] : null,
            'created_at' => $p->created_at?->toIso8601String(),
            'can_delete' => $staff && GalleryAccess::canDeletePhoto($user, $p),
        ];
    }

    private static function consentList(Album $album): array
    {
        return GalleryAccess::consentWithheld($album)->map(fn ($s) => ['id' => $s->id, 'full_name' => $s->full_name, 'student_no' => $s->student_no])->values()->all();
    }
}
