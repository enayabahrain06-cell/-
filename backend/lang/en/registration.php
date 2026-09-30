<?php

return [
    'submitted' => 'Your registration request was received. A WhatsApp confirmation is on its way.',
    'accepted' => 'Request accepted and the student account was created.',
    'bulk_done' => ':count requests accepted.',
    'errors' => [
        'closed' => 'This package is not open for registration.',
        'age' => 'The student will be :age at the package start date, outside the allowed range (:min–:max).',
        'gender' => 'This package is not for the student\'s gender.',
        'full' => 'The package is full. Force-accept or move the request to the waitlist.',
        'already_accepted' => 'This request was already accepted.',
        'photo_required' => 'A student photo is required before acceptance (see settings).',
        'not_found' => 'No request matches that number and phone.',
        'package_in_use' => 'A package with requests or classes cannot be deleted.',
    ],
    'alerts' => [
        'pending_title' => ':count registration requests awaiting a decision — :package',
    ],
];
