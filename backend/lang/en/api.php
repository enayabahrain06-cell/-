<?php

return [
    'saved' => 'Saved successfully.',
    'deleted' => 'Deleted.',
    'unauthenticated' => 'Please log in first.',
    'forbidden' => 'You do not have permission for this action.',
    'not_found' => 'The requested item was not found.',

    'auth' => [
        'failed' => 'Incorrect phone number or password.',
        'use_otp' => 'This account signs in with a WhatsApp code. Request a code instead.',
        'inactive' => 'This account is suspended. Contact the administration.',
        'phone_not_registered' => 'This phone number is not registered.',
        'logged_out' => 'Logged out.',
    ],

    'otp' => [
        'sent' => 'A verification code was sent on WhatsApp.',
        'cooldown' => 'Please wait :seconds seconds before requesting a new code.',
        'not_found' => 'No active code. Request a new one.',
        'expired' => 'The code has expired. Request a new one.',
        'invalid' => 'Incorrect code. Attempts left: :left',
        'too_many_attempts' => 'Too many attempts. Request a new code.',
    ],

    'roles' => [
        'super_admin_locked' => 'Super Admin permissions cannot be edited.',
    ],
];
