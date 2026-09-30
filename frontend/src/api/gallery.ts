import { api } from './client'

/** معرض الصور (Phase 8). Files are never URLs in these payloads: load them with galleryFileUrl() through authBlob. */

export type AlbumVisibility = 'staff' | 'linked' | 'all_guardians'
export type AlbumLinkType = 'lesson' | 'level' | 'competition' | 'activity'
export type AlbumGender = 'male' | 'female' | 'mixed'
export type GalleryVariant = 'thumb' | 'image' | 'video'

export interface Album {
  id: number
  title: string
  description: string | null
  album_date: string | null
  term: { id: number; name: string } | null
  gender: AlbumGender
  link: { type: AlbumLinkType; id: number; name: string | null } | null
  cover: { id: number; kind: 'photo' | 'video' } | null
  cover_photo_id: number | null
  photos_count: number
  /** Staff only (null in the family portal). */
  visibility: AlbumVisibility | null
  allow_download: boolean
  shared_at: string | null
  created_by: { id: number; name: string } | null
  can: { upload: boolean; edit: boolean; share: boolean; delete: boolean; download: boolean }
}

export interface AlbumPhoto {
  id: number
  kind: 'photo' | 'video'
  caption: string | null
  position: number
  width: number | null
  height: number | null
  duration_seconds: number | null
  uploaded_by: { id: number; name: string } | null
  created_at: string | null
  can_delete: boolean
}

export interface ConsentStudent { id: number; full_name: string; student_no: string }
export interface AlbumDetail extends Album { photos: AlbumPhoto[]; consent_withheld?: ConsentStudent[] }

export interface GalleryOptions {
  classes: { id: number; name: string; gender: AlbumGender | null; level_id: number | null }[]
  levels: { id: number; name: string }[]
  competitions: { id: number; name: string; gender: AlbumGender }[]
  /** Programs and trips of the term (managers only). */
  activities: { id: number; name: string; type: 'program' | 'trip'; gender: AlbumGender | null }[]
  link_types: AlbumLinkType[]
  video_enabled: boolean
  max_upload_mb: number
  video_max_mb: number
  video_max_seconds: number
  track: 'male' | 'female' | null
  can_manage: boolean
  can_create: boolean
}

export interface AlbumFilters { link_type?: AlbumLinkType; link_id?: number; visibility?: AlbumVisibility; q?: string; page?: number; per_page?: number; term_id?: number | 'all' }
export interface AlbumInput {
  title: string
  description?: string | null
  album_date: string
  gender?: AlbumGender | null
  link_type?: AlbumLinkType | null
  link_id?: number | null
  cover_photo_id?: number | null
}
export interface Paged<T> { data: T[]; meta: { current_page: number; last_page: number; total: number } }

/** Path of one file of a photo, relative to the API base (GET with the session token only). */
export const galleryFileUrl = (photoId: number, variant: GalleryVariant) => `/gallery/photos/${photoId}/${variant}`

export const galleryApi = {
  options: () => api.get<GalleryOptions>('/gallery/options').then((r) => r.data),
  albums: (f: AlbumFilters = {}) => api.get<Paged<Album>>('/gallery/albums', { params: f }).then((r) => r.data),
  album: (id: number) => api.get<{ data: AlbumDetail }>(`/gallery/albums/${id}`).then((r) => r.data.data),
  create: (data: AlbumInput) => api.post<{ data: Album }>('/gallery/albums', data).then((r) => r.data.data),
  update: (id: number, data: Partial<AlbumInput>) => api.put<{ data: Album }>(`/gallery/albums/${id}`, data).then((r) => r.data.data),
  remove: (id: number) => api.delete(`/gallery/albums/${id}`),
  share: (id: number, data: { visibility: AlbumVisibility; allow_download: boolean; notify: boolean }) =>
    api.put<{ data: Album; notified: number }>(`/gallery/albums/${id}/sharing`, data).then((r) => r.data),
  reorder: (id: number, ids: number[]) => api.post<{ data: number[] }>(`/gallery/albums/${id}/reorder`, { ids }).then((r) => r.data.data),
  consent: (id: number) => api.get<{ data: ConsentStudent[] }>(`/gallery/albums/${id}/consent`).then((r) => r.data.data),
  upload: (albumId: number, file: Blob, name: string, onProgress?: (fraction: number) => void) => {
    const form = new FormData()
    form.append('file', file, name)
    return api.post<{ data: AlbumPhoto }>(`/gallery/albums/${albumId}/photos`, form, {
      onUploadProgress: (e) => onProgress?.(e.total ? e.loaded / e.total : 0),
    }).then((r) => r.data.data)
  },
  caption: (photoId: number, caption: string | null) => api.put<{ data: AlbumPhoto }>(`/gallery/photos/${photoId}`, { caption }).then((r) => r.data.data),
  removePhoto: (photoId: number) => api.delete(`/gallery/photos/${photoId}`),
}

/** الصور in the family portal. */
export const familyGalleryApi = {
  albums: () => api.get<{ data: Album[] }>('/me/gallery').then((r) => r.data.data),
  album: (id: number) => api.get<{ data: AlbumDetail }>(`/me/gallery/${id}`).then((r) => r.data.data),
}
