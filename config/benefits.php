<?php

return [
    // Synthetic MySQL is opt-in and only valid for the guarded test fixture.
    'synthetic_mysql' => (bool) env('BENEFITS_SYNTHETIC_MYSQL', false),
];
