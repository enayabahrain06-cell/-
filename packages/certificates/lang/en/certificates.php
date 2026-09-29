<?php

return [
    'types' => [
        'achievement' => 'Achievement',
        'excellence' => 'Excellence',
        'participation' => 'Participation',
        'attendance' => 'Full attendance',
    ],
    'grades' => ['excellent' => 'Excellent', 'very_good' => 'Very good', 'good' => 'Good'],
    'sources' => ['manual' => 'Manual'],
    'status' => ['draft' => 'Draft', 'approved' => 'Approved', 'revoked' => 'Revoked'],

    // Default template texts per type (editable in the template editor). "default" is used for types without their own.
    'defaults' => [
        'default' => ['title' => 'Certificate', 'body' => 'is awarded this certificate for {achievement}.'],
        'achievement' => ['title' => 'Certificate of Achievement', 'body' => 'is awarded this certificate for {achievement}.'],
        'excellence' => ['title' => 'Certificate of Excellence', 'body' => 'is honored for excellence in {achievement}.'],
        'participation' => ['title' => 'Certificate of Appreciation', 'body' => 'is thanked for participating in {achievement}.'],
        'attendance' => ['title' => 'Full Attendance Certificate', 'body' => 'is honored for full attendance in {achievement}.'],
    ],

    // Printed on the PDF.
    'certify' => 'certifies that',
    'grade_line' => 'Grade',
    'issued_on' => 'Issued on',
    'certificate_no' => 'Certificate no',
    'verify_hint' => 'Scan to verify',
    'draft_mark' => 'DRAFT — NOT VALID',
    'revoked_mark' => 'REVOKED',
    'sample_name' => 'Jane Doe',
    'sample_achievement' => 'the Advanced Course',

    'messages' => [
        'created' => '{1} One draft certificate created.|[2,*] :count draft certificates created.',
        'updated' => 'Certificate updated.',
        'approved' => '{0} No certificates approved.|{1} One certificate approved.|[2,*] :count certificates approved.',
        'revoked' => 'Certificate revoked.',
        'deleted' => 'Draft certificate deleted.',
        'sent' => 'Certificate sent.',
        'template_saved' => 'Template saved.',
        'signature_saved' => 'Signature saved.',
        'signature_removed' => 'Signature removed.',
    ],
    'errors' => [
        'not_draft' => 'Only draft certificates can be changed.',
        'not_approved' => 'Only approved certificates can be revoked or sent.',
        'not_found' => 'No certificate matches this verification code.',
        'unknown_type' => 'Unknown certificate type.',
        'no_recipients' => 'No recipient type is configured.',
        'recipient_missing' => 'One or more recipients were not found.',
    ],
    'verify' => [
        'valid' => 'Valid certificate',
        'revoked' => 'This certificate has been revoked',
    ],
];
