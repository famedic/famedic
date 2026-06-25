<?php

return [
    'validation_ttl_minutes' => max(1, (int) env('PROMO_CODE_VALIDATION_TTL_MINUTES', 15)),
    'code_length' => max(6, (int) env('PROMO_CODE_LENGTH', 8)),
    'code_prefix' => strtoupper((string) env('PROMO_CODE_PREFIX', 'FAM')),
    'code_segment_length' => max(3, min(8, (int) env('PROMO_CODE_SEGMENT_LENGTH', 4))),
];
