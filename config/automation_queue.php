<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automation Queue & Retry Engine
    |--------------------------------------------------------------------------
    |
    | OrderAutomationDispatcher enqueues AutomationExecutionJob per driver.
    | Workers execute drivers asynchronously with idempotency + backoff.
    |
    */

    'queue' => env('AUTOMATION_QUEUE', 'automation'),

    'connection' => env('AUTOMATION_QUEUE_CONNECTION', null),

    /*
    | Max driver attempts including the first execution.
    | After exhausting retries → dead letter.
    */
    'max_attempts' => 5,

    /*
    | Delay (seconds) before each retry after a failed attempt.
    | Index 0 = after attempt 1, index 1 = after attempt 2, …
    */
    'backoff_seconds' => [60, 300, 900, 3600],

    'retryable_http_statuses' => [429, 500, 502, 503, 504],

    'retryable_error_patterns' => [
        'timeout',
        'timed out',
        'cURL error',
        'Connection refused',
        'Could not resolve host',
        'Connection reset',
        'SSL',
        'network',
        'HTTP 429',
        'HTTP 500',
        'HTTP 502',
        'HTTP 503',
        'HTTP 504',
    ],

    'job_timeout' => 120,

];
