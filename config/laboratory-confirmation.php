<?php

return [
    // Independent operational rollout. Both values must be set deliberately.
    'enabled' => filter_var(env('LAB_CONFIRMATION_COORDINATOR_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'activated_at' => env('LAB_CONFIRMATION_COORDINATOR_ACTIVATED_AT'),
    'content_wait_seconds' => (int) env('LAB_CONFIRMATION_CONTENT_WAIT_SECONDS', 15),
    'connection' => env('LAB_CONFIRMATION_QUEUE_CONNECTION', 'database'),
    'queue' => env('LAB_CONFIRMATION_QUEUE', 'laboratory-confirmations'),
    'generation_queue' => env('LAB_CONFIRMATION_GENERATION_QUEUE', 'laboratory-preparation'),
    'transport_timeout_seconds' => 20,
    'lease_seconds' => 120,
    'max_attempts' => 3,
    'retry_delays' => [30, 120],
    'mailer' => null, // Uses the configured default, with its own bounded timeout.
];
