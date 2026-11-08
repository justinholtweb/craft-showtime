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
    'Query for membership plans' => 'Query for membership plans',
    'Query for the current user’s membership and access checks' => 'Query for the current user’s membership and access checks',
    'Stripe webhooks are refused because no signing secret is set. New memberships won’t activate and cancellations won’t be applied.' => 'Stripe webhooks are refused because no signing secret is set. New memberships won’t activate and cancellations won’t be applied.',
    'Stripe webhooks are being rejected ({reason}). Check that the signing secret matches the endpoint in Stripe — sandboxes, test mode and live mode each have their own.' => 'Stripe webhooks are being rejected ({reason}). Check that the signing secret matches the endpoint in Stripe — sandboxes, test mode and live mode each have their own.',
    'A member checked out with Stripe, but no Stripe webhook has arrived since. Check the webhook endpoint in your Stripe dashboard — renewals, failed payments and cancellations won’t reach Headcount until it does.' => 'A member checked out with Stripe, but no Stripe webhook has arrived since. Check the webhook endpoint in your Stripe dashboard — renewals, failed payments and cancellations won’t reach Headcount until it does.',
    'The last Stripe webhook ({type}) failed: {error}' => 'The last Stripe webhook ({type}) failed: {error}',
    'Webhook status' => 'Webhook status',
    'Webhook Status' => 'Webhook Status',
    'Last webhook received' => 'Last webhook received',
    'Last webhook rejected' => 'Last webhook rejected',
    'Last signup recorded at checkout return' => 'Last signup recorded at checkout return',
    'Never' => 'Never',
    'None' => 'None',
    'Stripe shows every delivery attempt and the response this site gave under Developers → Webhooks → your endpoint. Subscriptions Headcount missed can be pulled in with {command}.' => 'Stripe shows every delivery attempt and the response this site gave under Developers → Webhooks → your endpoint. Subscriptions Headcount missed can be pulled in with {command}.',
];
