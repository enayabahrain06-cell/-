<?php

use Ahl\Certificates\Models\Certificate;
use Ahl\Certificates\Models\CertificateTemplate;
use Ahl\Certificates\Policies\CertificatePolicy;
use Ahl\Certificates\Support\DefaultHost;
use Ahl\Certificates\Support\DiskFileStore;
use Ahl\Certificates\Support\DompdfRenderer;
use Ahl\Certificates\Support\NullNotifier;

return [

    /*
    | Models and policy. Extend the package models to add your own relations, and point these at the subclasses.
    */
    'models' => [
        'certificate' => Certificate::class,
        'template' => CertificateTemplate::class,
    ],
    'policy' => CertificatePolicy::class,

    /*
    | The host adapter: visibility scopes, recipient search and presentation, settings, placeholders, branding.
    | Extend Ahl\Certificates\Support\DefaultHost and override what your system needs.
    */
    'host' => DefaultHost::class,

    /*
    | Swappable services.
    |  notifier: tells the recipient about an approved certificate (WhatsApp, e-mail, …). NullNotifier sends nothing.
    |  files:    where the approved PDF and signature images are stored.
    |  pdf:      turns the Blade view into PDF bytes.
    */
    'notifier' => NullNotifier::class,
    'files' => DiskFileStore::class,
    'pdf_renderer' => DompdfRenderer::class,

    /*
    | Who can receive certificates: API alias => Eloquent model (the model implements Contracts\Recipient).
    | The first entry is the default for POST /certificates.
    */
    'recipients' => [
        // 'student' => App\Models\Student::class,
    ],
    /* Column the list search matches on recipient models (DefaultHost::searchRecipients); null turns it off. */
    'recipient_search_column' => 'name',

    /*
    | Optional group a certificate belongs to (a class, course, circle …): API alias => model. Its name fills {context}.
    */
    'contexts' => [
        // 'course' => App\Models\Course::class,
    ],

    /*
    | Certificate types, grades and sources. Key => label: a translation key, a plain string, or ['en' => …, 'ar' => …].
    | Each type gets one editable template.
    */
    'types' => [
        'achievement' => 'certificates::certificates.types.achievement',
        'excellence' => 'certificates::certificates.types.excellence',
        'participation' => 'certificates::certificates.types.participation',
        'attendance' => 'certificates::certificates.types.attendance',
    ],
    'grades' => [
        'excellent' => 'certificates::certificates.grades.excellent',
        'very_good' => 'certificates::certificates.grades.very_good',
        'good' => 'certificates::certificates.grades.good',
    ],
    'sources' => [
        'manual' => 'certificates::certificates.sources.manual',
    ],

    /* Languages a template is written in. The recipient's locale picks one when rendering. */
    'locales' => ['en', 'ar'],
    'fallback_locale' => 'en',

    /* Default template texts: "{prefix}.{type}.title" and ".body", falling back to "{prefix}.default.*". */
    'defaults_translation' => 'certificates::certificates.defaults',

    /* Behaviour defaults. DefaultHost::setting() reads these; a host may read them from its own settings store. */
    'require_approval' => true,
    'notify_on_approve' => true,
    'link_minutes' => 30,

    /* Serial numbers: prefix + two-digit year + zero-padded sequence (C2600001). */
    'number' => ['prefix' => 'C', 'digits' => 5],

    /* Public verification page (the QR code target). {token} is replaced. */
    'verify_url' => env('CERTIFICATES_VERIFY_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/verify/{token}'),

    /* Name printed as the issuer and shown on the verification page. String or ['en' => …, 'ar' => …]. */
    'issuer' => env('APP_NAME', 'Laravel'),

    'pdf' => [
        'view' => 'certificates::pdf.certificate',
        'paper' => 'a4',
        'orientation' => 'landscape',
    ],

    'storage' => [
        'disk' => env('CERTIFICATES_DISK', 'local'),
        'directory' => 'certificates',
    ],

    'routes' => [
        'enabled' => true,
        'prefix' => 'api',
        'middleware' => ['api', 'auth:sanctum'],
        'public_middleware' => ['api'],
    ],

    /* Run the package migrations. Turn off when the host ships its own (for example when migrating existing tables). */
    'migrations' => true,
];
