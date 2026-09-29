<?php

use App\Certificates\AhlCertificateHost;
use App\Certificates\AhlPdfRenderer;
use App\Certificates\MediaFileStore;
use App\Certificates\WhatsAppCertificateNotifier;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\Lesson;
use App\Models\Student;
use App\Policies\CertificatePolicy;

/*
| Certificates package (packages/certificates, ahl/laravel-certificates) set up for this system.
| Every option is documented in packages/certificates/config/certificates.php.
*/
return [
    'models' => [
        'certificate' => Certificate::class,
        'template' => CertificateTemplate::class,
    ],
    'policy' => CertificatePolicy::class,
    'host' => AhlCertificateHost::class,
    'notifier' => WhatsAppCertificateNotifier::class,
    'files' => MediaFileStore::class,
    'pdf_renderer' => AhlPdfRenderer::class,

    'recipients' => ['student' => Student::class],
    'contexts' => ['lesson' => Lesson::class],

    // Labels come from lang/*/enums.php (App\Enums\CertificateType, CertificateGrade, CertificateSource).
    'types' => [
        'completion' => 'enums.certificate_type.completion',
        'excellence' => 'enums.certificate_type.excellence',
        'competition' => 'enums.certificate_type.competition',
        'exam' => 'enums.certificate_type.exam',
        'attendance' => 'enums.certificate_type.attendance',
        'participation' => 'enums.certificate_type.participation',
    ],
    'grades' => [
        'excellent' => 'enums.certificate_grade.excellent',
        'very_good' => 'enums.certificate_grade.very_good',
        'good' => 'enums.certificate_grade.good',
    ],
    'sources' => [
        'manual' => 'enums.certificate_source.manual',
        'auto_juz' => 'enums.certificate_source.auto_juz',
        'exam' => 'enums.certificate_source.exam',
        'honor_period' => 'enums.certificate_source.honor_period',
        'competition' => 'enums.certificate_source.competition',
        'legacy' => 'enums.certificate_source.legacy',
    ],

    'locales' => ['ar', 'en'],
    'fallback_locale' => 'ar',
    'defaults_translation' => 'certificates.defaults',

    // Read through AhlCertificateHost::setting() from the settings store (certificates.*); these are fallbacks.
    'require_approval' => true,
    'notify_on_approve' => true,
    'link_minutes' => 30,

    'number' => ['prefix' => 'C', 'digits' => 5],
    'verify_url' => rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/').'/verify/{token}',

    'pdf' => [
        'view' => 'pdf.certificate',
        'paper' => 'a4',
        'orientation' => 'landscape',
    ],

    'routes' => [
        'enabled' => true,
        'prefix' => 'api',
        'middleware' => ['api', 'auth:sanctum', 'active', 'throttle:api'],
        'public_middleware' => ['api'],
    ],

    // The tables predate the package: database/migrations/2026_09_29_000001_certificates_package.php converts them.
    'migrations' => false,
];
