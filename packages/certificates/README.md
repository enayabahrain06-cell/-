# ahl/laravel-certificates

Certificates for any Laravel 11/12 app: one editable template per certificate type (title and text in
every configured language, two signatures, ornament level, optional photo), a **draft → approved →
revoked** workflow, PDF rendering with a verification QR code, signed download links and a public
verification endpoint. Certificates are issued to **any Eloquent model** (student, employee, trainee …).

The React screens for this API are in [`@ahl/certificates-react`](../certificates-react/README.md).

## Install

From Packagist or a VCS repository once published; inside a monorepo, as a path repository:

```jsonc
// composer.json
"repositories": { "certificates": { "type": "path", "url": "../packages/certificates", "options": { "symlink": true } } },
"require": { "ahl/laravel-certificates": "@dev" }
```

```bash
composer update ahl/laravel-certificates
php artisan vendor:publish --tag=certificates-config   # config/certificates.php
php artisan migrate                                     # certificate_templates + certificates
```

Requirements: `barryvdh/laravel-dompdf`, `bacon/bacon-qr-code` (pulled in), the PHP `gd` extension, and
an auth guard for the API routes (Sanctum by default).

## Minimum setup

1. **Recipients.** The model implements `Ahl\Certificates\Contracts\Recipient` and uses `HasCertificates`:

   ```php
   class Employee extends Model implements Recipient
   {
       use HasCertificates;                      // $employee->certificates()

       public function certificateName(): string { return $this->full_name; }
       public function certificateLocale(): ?string { return $this->locale; }   // one of certificates.locales
       public function certificatePhoto(): ?string { return null; }             // data: URI when templates show photos
   }
   ```

   ```php
   // config/certificates.php
   'recipients' => ['employee' => App\Models\Employee::class],   // alias used by the API
   'contexts'   => ['course' => App\Models\Course::class],       // optional group, fills {context}
   'types'      => ['completion' => 'Course completion', 'excellence' => ['en' => 'Excellence', 'ar' => 'تميّز']],
   'locales'    => ['en', 'ar'],
   'verify_url' => 'https://example.com/verify/{token}',          // your public page (the QR target)
   ```

2. **Permissions.** The default policy checks four abilities: `certificates.view`, `certificates.issue`,
   `certificates.approve`, `certificates.templates` (for example spatie/laravel-permission permissions).
   A recipient that is the signed-in user sees their own approved certificates.

3. **Issue from code** (exams, courses, automatic rules):

   ```php
   app(Ahl\Certificates\CertificateService::class)->createDraft($employee, 'completion', [
       'achievement' => 'Fire safety course',
       'grade' => 'excellent',
       'context' => $course,
       'source' => 'course', 'source_id' => $course->id,   // exists($recipient, 'course', $id) prevents duplicates
   ], auth()->user());
   ```

   With `require_approval` off, drafts are approved (and the PDF stored) immediately.

## Extension points

| Config key | Contract | Default | Purpose |
|---|---|---|---|
| `host` | `Contracts\Host` (extend `Support\DefaultHost`) | `DefaultHost` | Settings source, list visibility per user, recipient search and API card, extra placeholders, issuer name, second calendar (e.g. Hijri), display time zone, verify URL, extra PDF data |
| `notifier` | `Contracts\CertificateNotifier` | `NullNotifier` (send hidden) | Tell the recipient on approval and on "send again" (e-mail, WhatsApp, SMS) |
| `files` | `Contracts\FileStore` | `DiskFileStore` (`storage.disk`) | Approved PDFs (`pdf`) and signature images (`signature_1`, `signature_2`) |
| `pdf_renderer` | `Contracts\PdfRenderer` | `DompdfRenderer` | Fonts, text shaping, another PDF engine |
| `pdf.view` | Blade view | `certificates::pdf.certificate` | The printed design (publish with `--tag=certificates-views`) |
| `models.*` | extend `Models\Certificate` / `Models\CertificateTemplate` | package models | Add relations; the route binding and policy follow the configured class |
| `policy` | extend `Policies\CertificatePolicy` | package policy | Your own rules (`issueFor`, `approve`, `viewRecipient`, `manageRecipient` …) |

Events: `CertificateDrafted`, `CertificateApproved`, `CertificateRevoked` (audit logs, webhooks).
Texts: `lang/vendor/certificates/{locale}/certificates.php` overrides any key; `defaults_translation`
points the default template texts at your own translation file.

Arabic: dompdf does not shape Arabic. Use a view that shapes text (for example with `ar-php`) and a
`PdfRenderer` that registers Arabic fonts, as this repository's host app does
(`backend/resources/views/pdf/certificate.blade.php`, `App\Certificates\AhlPdfRenderer`).

## API

Authenticated (`routes.middleware`, prefix `routes.prefix`):

| Method | Path | |
|---|---|---|
| GET | `/certificates/options` | types, grades, sources, statuses, locales, placeholders, `require_approval`, `can_send` |
| GET | `/certificates` | list; filters `status type source recipient_type recipient_id context_type context_id search from to page per_page`; `meta.status_counts` |
| POST | `/certificates` | `{recipient_type?, recipient_ids[], type, achievement, title?, grade?, context_type?, context_id?}`, one draft each |
| POST | `/certificates/approve` | `{ids[]}` bulk approve |
| GET | `/certificates/recipients/{type}/{id}` | one recipient's certificates with a summary (`total`, `by_type`, `drafts`, `latest`) |
| GET/PUT/DELETE | `/certificates/{id}` | show; edit a draft; delete a draft |
| POST | `/certificates/{id}/approve` · `/revoke` `{reason}` · `/send` | workflow |
| GET | `/certificates/{id}/pdf` | PDF for a signed-in viewer |
| GET/PUT | `/certificate-templates`, `/certificate-templates/{type}` | `{title: {en, ar}, body: {en, ar}, signature1_name …, ornament_level, show_photo}` |
| GET | `/certificate-templates/{type}/preview?locale=` | sample PDF |
| GET/POST/DELETE | `/certificate-templates/{type}/signatures/{1\|2}` | signature image (`image`, PNG/JPEG ≤ 1 MB) |

Public (`routes.public_middleware`): `GET /public/certificates/verify/{token}` (drafts are never
verifiable; revoked ones say so) and `GET /certificates/{id}/download` (temporary signed URL from the
resource: `download_url`, `view_url`, `print_url` counts a print).

Body placeholders: `{name}`, `{achievement}`, `{grade}`, `{context}`, `{date}` plus the host's own
(`Host::placeholders()`).
