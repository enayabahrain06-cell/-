# Phase 8 — معرض الصور (photo gallery)

Requested 2026-09-30. Queued as its own phase **after** the current school-management roadmap (phases 4–7), on `feature/school-management`, under the same rules: one unified system, the existing design system, permissions for all 5 roles in `RolePermissionSeeder`, ar + en strings, term scoping (default current term, "كل الفصول" option), reversible migrations on sqlite/mysql/pgsql, tests, one commit, stop for approval.

## Why after phase 7

Decided 2026-09-30: wait for phase 7. Albums link to trips and programs. Those arrive in phase 7 (programs + trips merged into one "activities" module). Building the gallery first would mean linking to tables that do not exist yet, or adding the link twice.

## Menu

`menu.gallery` = "معرض الصور" / "Photo gallery", in the `communication` section of `frontend/src/app/nav.ts`, after messages and reports. Guardians get a "الصور" tab in the family portal.

## Data (link, don't copy)

| Table | Columns |
|-------|---------|
| `albums` | id, title, description, album_date, academic_term_id, cover_photo_id (nullable), visibility (`staff` / `linked` / `all_guardians`, default `staff`), allow_download (boolean, default false: السماح بالتحميل), linkable_type + linkable_id (nullable morph: activity, competition, lesson (class), level), track (gender scoping like every other list), shared_at, created_by, timestamps |
| `album_photos` | id, album_id, media_id, caption, position, kind (`photo` / `video`), width, height, uploaded_by, timestamps |
| `students.photo_consent_withheld` | boolean, default false (عدم الموافقة على التصوير) |

Files go through the existing `media` table and `MediaService` (single source of truth, decision 5 of 2026-09-27). The trip/program/class/level pages read their album through the morph link; nothing is duplicated.

## Upload

- Many files at once: drag and drop on desktop, `<input type="file" accept="image/*" multiple capture>` on phones (camera or gallery).
- Server-side processing in a queued job, extending `PhotoProcessor` (Intervention, already installed): auto-orient, strip EXIF (removes GPS location from children's photos), resize to max 2048 px WebP, plus a 400 px thumbnail. The original is discarded.
- Max size 15 MB per photo before processing (setting `gallery.max_upload_mb`).
- Reorder (drag, saves `position`), set cover, delete (soft: media file removed, audit row kept).

## Viewing

- Album grid (cover, title, date, count, linked activity badge), photo grid inside, full-screen viewer with swipe on phones and arrow keys on desktop.
- Filters: term, linked activity / competition / class / level.
- Download: staff with `gallery.manage` always. Guardians only when the album's "السماح بالتحميل" toggle (`allow_download`) is on; it is off by default, and when off guardians can only view (no download button, and the file route serves the viewer size only, never an attachment response). Changing the toggle needs `gallery.manage` and is audited.

## Privacy

- No public URLs. Images and thumbnails are served by an authenticated route with a policy check (same pattern as the signed student photo route, 2.12), short-lived signed URLs, `Cache-Control: private`.
- Guardians see an album only when it is shared and one of their children matches: `linked` → the child is in the linked class / level / activity / competition; `all_guardians` → any guardian. `staff` albums never reach the portal.
- Only super_admin and supervisor (`gallery.manage`) can change visibility away from `staff`. Teachers can create albums for their own classes, but those albums are always `staff`; the API rejects a visibility change from a teacher, and the policy checks this, not only the UI.
- Audit log: album created, shared, visibility changed, photo uploaded (by whom), photo deleted, photo downloaded.
- Consent flag on the student profile (staff edit, audited). On upload to an album linked to a class / level / activity with such students, a warning lists their names (and photos, staff only) so the uploader can check the pictures. The warning is advisory; the system does not detect faces.

## Permissions

| Permission | super_admin | supervisor | teacher | guardian | student |
|------------|:-:|:-:|:-:|:-:|:-:|
| `gallery.view` | ✓ | ✓ | ✓ | per album visibility | — |
| `gallery.upload` (create albums for own classes, upload into them; always staff-only) | ✓ | ✓ | own classes | — | — |
| `gallery.manage` (any album; share with guardians / change visibility; السماح بالتحميل; delete, cover, reorder) | ✓ | ✓ | — | — | — |

## Optional

- WhatsApp to guardians when an album is shared with them (template `gallery_album_shared`, ar + en, uses the existing queue, quiet hours and de-duplication; one message per guardian per album).
- Short videos, as an optional add-on (decided 2026-09-30): accept short MP4 (≤ 60 s, ≤ 50 MB) stored as uploaded, with a poster thumbnail and duration check made by `ffmpeg`/`ffprobe`. The backend detects ffmpeg at runtime (configurable path, `gallery.video_enabled` derived from it) and reports it to the frontend. If ffmpeg is missing, the system works with photos only: the video option is hidden in the upload UI and the API rejects video files. The user is confirming whether production has ffmpeg.

## Decisions (2026-09-30)

1. Order: wait for phase 7; the gallery is built as phase 8 once trips and programs exist.
2. Videos: optional add-on, hidden when ffmpeg is missing (production ffmpeg still to be confirmed by the user).
3. Guardian downloads: per-album "السماح بالتحميل" toggle, off by default; off means view only.
4. Teachers create albums for their own classes, always staff-only; only supervisor and super_admin change visibility to guardians.
