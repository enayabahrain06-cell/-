<?php

namespace Ahl\Certificates\Contracts;

/**
 * A model that can receive certificates (a student, an employee, a trainee …).
 * Use the HasCertificates trait for the relation.
 */
interface Recipient
{
    /** Name printed on the certificate. */
    public function certificateName(): string;

    /** Language the certificate is rendered in (one of certificates.locales, otherwise the fallback). */
    public function certificateLocale(): ?string;

    /** Photo for templates with show_photo, as a data: URI, or null. */
    public function certificatePhoto(): ?string;
}
