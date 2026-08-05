<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Clinical Interpreter · Vision
    |--------------------------------------------------------------------------
    | Prompts live as versioned files under resources/clinical_interpreter/prompts.
    | The Matching Engine never calls OpenAI; only DocumentInterpreter does.
    */
    'active_prompt' => env('CLINICAL_INTERPRETER_PROMPT', 'prescription_v1'),

    'prompt_path' => resource_path('clinical_interpreter/prompts'),

    'openai' => [
        'model' => env('CLINICAL_INTERPRETER_MODEL', env('OPENAI_MODEL', 'gpt-4o')),
        'timeout' => (int) env('CLINICAL_INTERPRETER_TIMEOUT', 90),
        'endpoint' => 'https://api.openai.com/v1/chat/completions',
    ],

    /*
    | Approximate USD pricing per 1M tokens for cost estimates in logs/UI.
    | Update when OpenAI pricing changes.
    */
    'pricing' => [
        'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        'gpt-4.1' => ['input' => 2.00, 'output' => 8.00],
        'gpt-4.1-mini' => ['input' => 0.40, 'output' => 1.60],
        'default' => ['input' => 2.50, 'output' => 10.00],
    ],
];
