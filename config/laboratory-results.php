<?php

return [
    /**
     * Exigir OTP para ver/descargar resultados (área autenticada y liga pública por token).
     * Desactivado por defecto hasta estabilizar el flujo en producción.
     */
    'otp_required' => (bool) env('LAB_RESULTS_OTP_REQUIRED', false),

    /**
     * Tras validar OTP en el área autenticada, tiempo en minutos sin volver a pedir código (ventana de confianza).
     */
    'otp_trust_session_minutes' => (int) env('LAB_RESULTS_OTP_TRUST_MINUTES', 15),

    /**
     * Horas que el usuario puede volver a ver/descargar el PDF tras validar OTP (sesión de resultados).
     */
    'pdf_session_hours' => (int) env('LAB_RESULTS_PDF_SESSION_HOURS', 24),

    /**
     * Minutos de sesion para el flujo publico de resultados (liga de correo + OTP).
     */
    'public_session_minutes' => (int) env('LAB_RESULTS_PUBLIC_SESSION_MINUTES', 15),

    /**
     * Texto UX: "disponibles durante X horas" (mismo valor por defecto que la sesión firmada).
     */
    'availability_hours' => (int) env('LAB_RESULTS_AVAILABILITY_HOURS', 24),

    /** Segundos entre reenvíos de código OTP */
    'resend_seconds' => (int) env('LAB_RESULTS_RESEND_SECONDS', 60),

    /** Horas de validez para un enlace compartido */
    'share_link_hours' => (int) env('LAB_RESULTS_SHARE_LINK_HOURS', 12),

    /** Límite de peticiones por minuto e IP en rutas públicas de resultados (send/verify/resend) */
    'rate_limit_per_minute' => (int) env('LAB_RESULTS_RATE_LIMIT', 12),

    /**
     * Extracción estructurada determinística desde PDF (FASE 8C-2).
     * Desactivado por defecto: no altera el flujo GDA existente.
     */
    'structured_extraction' => [
        'enabled' => (bool) env('LAB_RESULTS_STRUCTURED_EXTRACTION_ENABLED', false),
        'extractor_version' => env('LAB_RESULTS_TEXT_EXTRACTOR_VERSION', 'RESULT_TEXT_EXTRACTOR_V1'),
        'min_total_characters' => (int) env('LAB_RESULTS_MIN_TOTAL_CHARACTERS', 50),
        'min_characters_per_page' => (int) env('LAB_RESULTS_MIN_CHARACTERS_PER_PAGE', 20),
    ],

    /**
     * Extracción Vision experimental (FASE 8C-3). Desactivada por defecto.
     */
    'vision_extraction' => [
        'enabled' => (bool) env('LAB_RESULTS_VISION_EXTRACTION_ENABLED', false),
        'shadow_mode' => (bool) env('LAB_RESULTS_VISION_SHADOW_MODE', true),
        'extractor_version' => env(
            'LAB_RESULTS_VISION_EXTRACTOR_VERSION',
            'RESULT_VISION_PII_SAFE_CROP_TSV_V3',
        ),
        'max_pages' => (int) env('LAB_RESULTS_VISION_MAX_PAGES', 3),
        'timeout' => (int) env('LAB_RESULTS_VISION_TIMEOUT', 90),
        'retry' => (int) env('LAB_RESULTS_VISION_RETRY', 0),
        'fallback' => [
            'min_characters' => (int) env('LAB_RESULTS_VISION_FALLBACK_MIN_CHARACTERS', 50),
            'min_text_density' => (float) env('LAB_RESULTS_VISION_FALLBACK_MIN_TEXT_DENSITY', 0.5),
            'min_observations' => (int) env('LAB_RESULTS_VISION_FALLBACK_MIN_OBSERVATIONS', 1),
            'min_confidence' => (float) env('LAB_RESULTS_VISION_FALLBACK_MIN_CONFIDENCE', 0.75),
        ],
        'experiment' => [
            'real_pdf_dpi' => (int) env('LAB_RESULTS_VISION_EXPERIMENT_REAL_PDF_DPI', 150),
            'pdftoppm_binary' => env('LAB_RESULTS_VISION_EXPERIMENT_PDFTOPPM', 'pdftoppm'),
        ],
        'pii_safe' => [
            'pdftotext_binary' => env('LAB_RESULTS_VISION_PDFTOTEXT', 'pdftotext'),
        ],
    ],

    /**
     * QA / observabilidad extracción híbrida (FASE 8C-4). Solo medición, sin salir de Shadow Mode.
     */
    'qa' => [
        'allowed_environments' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('LAB_RESULTS_QA_ALLOWED_ENVIRONMENTS', 'local,testing,qa'))
        ))),
    ],

    /**
     * Piloto Structured Results en Shadow/QA (FASE 8C-17D).
     * Desactivado por defecto: no publica ni altera patient flow.
     */
    'structured_shadow_qa' => [
        'enabled' => (bool) env('LAB_RESULTS_STRUCTURED_SHADOW_QA_ENABLED', false),
        'phase' => '8C-17D',
        'experiment_key' => '8c-17d_structured_shadow_qa',
        'extractor_version' => 'STRUCTURED_QA_SHADOW_V1',
        'manifest_path' => storage_path('app/laboratory-results/8c17d-structured-qa-manifest.json'),
        'fallback_sources' => [
            'recalc' => storage_path('tmp/8c17c_structured_qa_recalc.json'),
            'validation' => storage_path('tmp/8c17b_cbc_validation.json'),
            'purchase_investigation' => storage_path('tmp/8c17c_purchase_item_investigation.json'),
        ],
        'excluded_pdf_patterns' => ['gda-2423'],
    ],

    /**
     * Promotion gate Shadow QA (FASE 8C-18). Validated != Published.
     */
    'promotion_gate' => [
        'version' => 'promotion_gate_v1',
        'min_confidence' => (float) env('LAB_RESULTS_PROMOTION_MIN_CONFIDENCE', 0.75),
    ],

    /**
     * Aprobación humana para publicación futura (FASE 8C-19A). Approved != Published.
     */
    'publication_approval' => [
        'version' => 'publication_approval_v1',
        'permission' => 'laboratory-results.approve-publication',
    ],

    /**
     * Publicación controlada de Structured Results (FASE 8C-19B). Desactivado por defecto.
     */
    'structured_publication' => [
        'enabled' => (bool) env('LAB_RESULTS_STRUCTURED_PUBLICATION_ENABLED', false),
        'gate_version' => 'publication_gate_v1',
        'permission' => 'laboratory-results.publish',
    ],

    /**
     * Explicaciones AI educativas para resultados estructurados publicados (FASE 8C-21B).
     */
    'ai_explanation' => [
        'enabled' => (bool) env('LAB_RESULTS_AI_EXPLANATION_ENABLED', false),
        'consent_required' => (bool) env('LAB_RESULTS_AI_EXPLANATION_CONSENT_REQUIRED', true),
        /**
         * Entornos donde la feature puede activarse (FASE 8C-21E).
         * production NUNCA está permitido aunque el flag esté en true.
         */
        'allowed_environments' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env(
                'LAB_RESULTS_AI_EXPLANATION_ALLOWED_ENVIRONMENTS',
                'local,testing,qa,staging',
            ))
        ))),
    ],
];
