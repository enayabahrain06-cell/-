<?php

return [
    // Default template texts per certificate type (editable in Certificates → Templates).
    'defaults' => [
        'completion' => ['title' => 'Certificate of Memorization', 'body' => 'has completed the memorization of {achievement}. May Allah make the Quran the spring of their heart.'],
        'excellence' => ['title' => 'Certificate of Excellence', 'body' => 'is honored for excellence in {achievement}.'],
        'competition' => ['title' => 'Competition Certificate', 'body' => 'has won {achievement}. We pray for continued success.'],
        'exam' => ['title' => 'Certificate of Passing', 'body' => 'has passed {achievement}.'],
        'attendance' => ['title' => 'Full Attendance Certificate', 'body' => 'is honored for full attendance in {achievement}.'],
        'participation' => ['title' => 'Certificate of Appreciation', 'body' => 'is thanked for participating in {achievement}.'],
    ],
    'certify' => 'certifies that the student',
    'grade_line' => 'Grade',
    'issued_on' => 'Issued on',
    'hijri' => 'AH',
    'certificate_no' => 'Certificate no',
    'verify_hint' => 'Scan to verify',
    'draft_mark' => 'DRAFT — NOT VALID',
    'revoked_mark' => 'REVOKED',
    'sample_name' => 'Hussain Ali Al-Mahroos',
    'sample_achievement' => 'Juz Amma',
    'auto_juz' => 'Juz :juz',
    'auto_quran' => 'the whole Holy Quran',
    'messages' => [
        'created' => '{1} One draft certificate created.|[2,*] :count draft certificates created.',
        'updated' => 'Certificate updated.',
        'approved' => '{1} One certificate approved.|[2,*] :count certificates approved.',
        'revoked' => 'Certificate revoked.',
        'deleted' => 'Draft certificate deleted.',
        'sent' => 'Certificate sent on WhatsApp.',
        'template_saved' => 'Template saved.',
        'signature_saved' => 'Signature saved.',
        'signature_removed' => 'Signature removed.',
    ],
    'errors' => [
        'not_draft' => 'Only draft certificates can be changed.',
        'not_approved' => 'Only approved certificates can be revoked or sent.',
        'not_available' => 'This certificate is not available yet.',
        'duplicate' => 'The student already has an active certificate for this achievement.',
        'not_found' => 'No certificate matches this verification code.',
    ],
    'verify' => [
        'valid' => 'Valid certificate',
        'revoked' => 'This certificate has been revoked',
    ],
];
