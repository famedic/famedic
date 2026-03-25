<?php

return [
    /**
     * Horas que el usuario puede volver a ver/descargar el PDF tras validar OTP (sesión de resultados).
     */
    'pdf_session_hours' => (int) env('LAB_RESULTS_PDF_SESSION_HOURS', 24),

    /**
     * Texto UX: "disponibles durante X horas" (mismo valor por defecto que la sesión firmada).
     */
    'availability_hours' => (int) env('LAB_RESULTS_AVAILABILITY_HOURS', 24),

    /** Segundos entre reenvíos de código OTP */
    'resend_seconds' => (int) env('LAB_RESULTS_RESEND_SECONDS', 30),

    /** Horas de validez para un enlace compartido */
    'share_link_hours' => (int) env('LAB_RESULTS_SHARE_LINK_HOURS', 12),

    /** Límite de peticiones por minuto e IP en rutas públicas de resultados (send/verify/resend) */
    'rate_limit_per_minute' => (int) env('LAB_RESULTS_RATE_LIMIT', 12),
];
