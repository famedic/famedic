<?php

return [
    'enabled' => env('LOCAL_CHECKOUT_QA_ENABLED', false),
    'recipient' => env('LOCAL_CHECKOUT_QA_RECIPIENT', ''),
    'scenario' => env('LOCAL_CHECKOUT_QA_SCENARIO', 'auto'),
];
