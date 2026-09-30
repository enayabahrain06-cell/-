<?php

namespace App\Services\Gallery;

use App\Enums\MediaCollection;
use App\Enums\MessageType;
use App\Enums\RecipientType;
use App\Models\Album;
use App\Models\AlbumPhoto;
use App\Models\Student;
use App\Services\AuditLogger;
use App\Services\Media\MediaService;
use App\Services\Messaging\MessageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** معرض الصور: uploads, deletions, order and sharing. Every change is audited. */
class GalleryService
{
    public function __construct(
        private MediaService $media,
        private GalleryImageProcessor $images,
        private VideoSupport $video,
        private AuditLogger $audit,
        private MessageService $messages,
    ) {}

    public function upload(Album $album, UploadedFile $file, ?string $caption, int $userId): AlbumPhoto
    {
        $mime = (string) $file->getMimeType();
        $path = (string) $file->getRealPath();
        $size = (int) $file->getSize();

        if ($this->video->isVideo($mime)) {
            $info = $this->video->inspect($path, $size);
            try {
                $poster = $this->images->encode($info['poster']);
            } finally {
                @unlink($info['poster']);
            }
            $photo = DB::transaction(function () use ($album, $file, $caption, $userId, $poster, $info) {
                $photo = $this->row($album, 'video', $caption, $userId, $poster['width'], $poster['height'], $info['duration']);
                $this->media->storeUpload($photo, MediaCollection::GalleryVideo, $file, replace: false);
                $this->media->storeContents($photo, MediaCollection::GalleryImage, $poster['image'], 'webp', 'image/webp', replace: false);
                $this->media->storeContents($photo, MediaCollection::GalleryThumb, $poster['thumb'], 'webp', 'image/webp', replace: false);

                return $photo;
            });
        } elseif (str_starts_with(strtolower($mime), 'image/')) {
            $out = $this->images->process($path, $mime, $size);
            $photo = DB::transaction(function () use ($album, $caption, $userId, $out) {
                $photo = $this->row($album, 'photo', $caption, $userId, $out['width'], $out['height'], null);
                $this->media->storeContents($photo, MediaCollection::GalleryImage, $out['image'], 'webp', 'image/webp', replace: false);
                $this->media->storeContents($photo, MediaCollection::GalleryThumb, $out['thumb'], 'webp', 'image/webp', replace: false);

                return $photo;
            });
        } else {
            throw ValidationException::withMessages(['file' => __($this->video->enabled() ? 'gallery.errors.type_video' : 'gallery.errors.type')]);
        }

        $this->audit->record('gallery.photo_uploaded', $photo, [], ['album_id' => $album->id, 'kind' => $photo->kind, 'uploaded_by' => $userId], $userId);

        return $photo;
    }

    public function deletePhoto(AlbumPhoto $photo): void
    {
        $album = $photo->album;
        DB::transaction(function () use ($photo, $album) {
            $photo->load('media')->media->each(fn ($m) => $this->media->delete($m));
            if ((int) $album->cover_photo_id === (int) $photo->id) {
                $album->update(['cover_photo_id' => null]);
            }
            $photo->delete();
        });
        $this->audit->record('gallery.photo_deleted', $photo, ['album_id' => $album->id, 'uploaded_by' => $photo->uploaded_by, 'kind' => $photo->kind]);
    }

    public function deleteAlbum(Album $album): void
    {
        $count = 0;
        DB::transaction(function () use ($album, &$count) {
            $album->photos()->with('media')->get()->each(function (AlbumPhoto $p) use (&$count) {
                $p->media->each(fn ($m) => $this->media->delete($m));
                $p->delete();
                $count++;
            });
            $album->delete();
        });
        $this->audit->record('gallery.album_deleted', $album, ['title' => $album->title, 'photos' => $count, 'visibility' => $album->visibility]);
    }

    /** @param list<int> $ids the album's photo ids in their new order (others keep their place after them) */
    public function reorder(Album $album, array $ids): void
    {
        $existing = $album->photos()->pluck('id')->all();
        $order = array_values(array_unique(array_merge(array_values(array_intersect($ids, $existing)), $existing)));
        DB::transaction(function () use ($order) {
            foreach ($order as $i => $id) {
                AlbumPhoto::whereKey($id)->update(['position' => $i + 1]);
            }
        });
    }

    /**
     * Visibility and السماح بالتحميل. Sharing (visibility leaves "staff") stamps shared_at; with $notify, the
     * guardians it reaches get one WhatsApp message each (de-duplicated per album and phone).
     *
     * @return int messages queued
     */
    public function share(Album $album, string $visibility, bool $allowDownload, bool $notify): int
    {
        $old = $album->only(['visibility', 'allow_download']);
        $album->visibility = $visibility;
        $album->allow_download = $allowDownload;
        if ($album->isShared() && ! $album->shared_at) {
            $album->shared_at = now();
        }
        if (! $album->isShared()) {
            $album->shared_at = null;
        }
        $album->save();
        $this->audit->record('gallery.album_shared', $album, $old, $album->only(['visibility', 'allow_download']));

        return $notify && $album->isShared() ? $this->notify($album) : 0;
    }

    private function notify(Album $album): int
    {
        $students = $album->visibility === 'all_guardians'
            ? Student::where('status', 'active')->when($album->gender !== 'mixed', fn ($q) => $q->where('gender', $album->gender))->get()
            : GalleryAccess::linkedStudentIds($album)->get();

        $link = config('ahl.frontend_url').'/my-gallery/'.$album->id;
        $sent = 0;
        foreach ($students as $s) {
            if (! $s->guardian_phone) {
                continue;
            }
            $phone = \App\Support\PhoneNumber::normalize($s->guardian_phone);
            $log = $phone ? $this->messages->send(
                phone: $phone, type: MessageType::AlbumShared,
                vars: ['album' => $album->title, 'date' => $album->album_date?->toDateString() ?? '', 'link' => $link],
                student: $s, recipientType: RecipientType::Guardian, dedupeKey: "album_shared:{$album->id}:{$phone}",
            ) : null;
            $sent += $log ? 1 : 0;
        }

        return $sent;
    }

    private function row(Album $album, string $kind, ?string $caption, int $userId, ?int $w, ?int $h, ?int $duration): AlbumPhoto
    {
        return AlbumPhoto::create([
            'album_id' => $album->id, 'kind' => $kind, 'caption' => $caption, 'uploaded_by' => $userId,
            'position' => (int) AlbumPhoto::where('album_id', $album->id)->max('position') + 1,
            'width' => $w, 'height' => $h, 'duration_seconds' => $duration,
        ]);
    }
}
