<?php

return [
    'Headcount' => 'Headcount',
    'Dashboard' => 'Dashboard',
    'Plans' => 'Plans',
    'Subscriptions' => 'Subscriptions',
    'Access Rules' => 'Access Rules',
    'Drip' => 'Drip',
    'Coupons' => 'Coupons',
    'Reports' => 'Reports',
    'Settings' => 'Settings',

    // Statuses
    'Active' => 'Active',
    'Trialing' => 'Trialing',
    'Past Due' => 'Past Due',
    'Canceled' => 'Canceled',
    'Expired' => 'Expired',
    'Paused' => 'Paused',

    // Plans
    'New Plan' => 'New Plan',
    'Edit Plan' => 'Edit Plan',
    'Plan saved.' => 'Plan saved.',
    'Couldn\'t save plan.' => 'Couldn\'t save plan.',

    // Subscriptions
    'New Subscription' => 'New Subscription',
    'Edit Subscription' => 'Edit Subscription',
    'All Subscriptions' => 'All Subscriptions',

    // Access Rules
    'New Access Rule' => 'New Access Rule',
    'Edit Access Rule' => 'Edit Access Rule',
    'Access rule saved.' => 'Access rule saved.',

    // Drip
    'New Drip Schedule' => 'New Drip Schedule',
    'Edit Drip Schedule' => 'Edit Drip Schedule',
    'Drip schedule saved.' => 'Drip schedule saved.',

    // Coupons
    'New Coupon' => 'New Coupon',
    'Edit Coupon' => 'Edit Coupon',
    'Coupon saved.' => 'Coupon saved.',

    // Gating
    'This content requires an active subscription.' => 'This content requires an active subscription.',
    'Content is locked. Subscribe to access.' => 'Content is locked. Subscribe to access.',

    // Billing
    'day' => 'day',
    'week' => 'week',
    'month' => 'month',
    'year' => 'year',
    'Stripe webhooks are refused until a signing secret is set — without one they can’t be verified. Memberships won’t activate, renew or cancel from Stripe events.' => 'Stripe webhooks are refused until a signing secret is set — without one they can’t be verified. Memberships won’t activate, renew or cancel from Stripe events.',
    'PayPal webhooks are refused until a webhook ID is set — without one they can’t be verified. Memberships won’t activate, renew or cancel from PayPal events.' => 'PayPal webhooks are refused until a webhook ID is set — without one they can’t be verified. Memberships won’t activate, renew or cancel from PayPal events.',
    'This environment variable doesn’t resolve, so API-key access is off.' => 'This environment variable doesn’t resolve, so API-key access is off.',
    'This environment variable doesn’t resolve, so outgoing webhooks aren’t sent.' => 'This environment variable doesn’t resolve, so outgoing webhooks aren’t sent.',
    'Handles must start with a letter and contain only letters, numbers, hyphens and underscores.' => 'Handles must start with a letter and contain only letters, numbers, hyphens and underscores.',
    'A plan with this handle already exists.' => 'A plan with this handle already exists.',
    'This is being overridden by the `{setting}` setting in `config/headcount.php`.' => 'This is being overridden by the `{setting}` setting in `config/headcount.php`.',
];
