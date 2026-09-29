<?php

// App-specific certificate texts. Workflow messages come from the certificates package
// (packages/certificates/lang), with this system's wording in lang/vendor/certificates.
return [
    // Default template texts per certificate type (editable in Certificates → Templates).
    'defaults' => [
        'completion' => ['title' => 'Certificate of Memorization', 'body' => 'has completed the memorization of {achievement}. May Allah make the Quran the spring of their heart.'],
        'excellence' => ['title' => 'Certificate of Excellence', 'body' => 'is honored for excellence in {achievement}.'],
        'competition' => ['title' => 'Competition Certificate', 'body' => 'has won {achievement}. We pray for continued success.'],
        'exam' => ['title' => 'Certificate of Passing', 'body' => 'has passed {achievement}.'],
        'attendance' => ['title' => 'Full Attendance Certificate', 'body' => 'is honored for full attendance in {achievement}.'],
        'participation' => ['title' => 'Certificate of Appreciation', 'body' => 'is thanked for participating in {achievement}.'],
        'default' => ['title' => 'Certificate', 'body' => 'is honored for {achievement}.'],
    ],
    // Printed on the PDF (resources/views/pdf/certificate.blade.php).
    'certify' => 'certifies that the student',
    'grade_line' => 'Grade',
    'issued_on' => 'Issued on',
    'certificate_no' => 'Certificate no',
    'verify_hint' => 'Scan to verify',
    // Automatic completion certificates.
    'auto_juz' => 'Juz :juz',
    'auto_quran' => 'the whole Holy Quran',
];
