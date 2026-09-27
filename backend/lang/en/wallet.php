<?php

return [
    'receipt' => ['title' => 'Payment receipt'],
    'errors' => [
        'amount_positive' => 'The amount must be greater than zero.',
        'amount_nonzero' => 'The amount cannot be zero.',
        'note_required' => 'A note explaining the adjustment is required.',
        'insufficient_credit' => 'Not enough credit to refund. Current balance: :balance',
        'cancel_paid' => 'A partially paid invoice cannot be cancelled.',
    ],
    'alerts' => [
        'overdue_title' => 'Overdue invoice :invoice — :name',
    ],
    'invoice' => [
        'package_fee' => 'Package fee: :package',
    ],
];
