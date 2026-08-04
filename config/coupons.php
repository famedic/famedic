<?php

return [
    /**
     * Exige verificación OTP al administrador antes de crear un cupón maestro nuevo.
     */
    'creation_otp_required' => (bool) env('COUPON_CREATION_OTP_REQUIRED', true),

    /**
     * Segundos de espera entre reenvíos de OTP para creación de cupón.
     */
    'creation_otp_resend_seconds' => max(30, (int) env('COUPON_CREATION_OTP_RESEND_SECONDS', 60)),

    /**
     * Minutos de validez del token de verificación tras validar OTP (ventana para confirmar guardado).
     */
    'creation_otp_verification_ttl_minutes' => max(1, (int) env('COUPON_CREATION_OTP_VERIFICATION_TTL', 15)),

    /**
     * Segundos de espera entre reenvíos de OTP para aprobar autorizaciones.
     */
    'authorization_otp_resend_seconds' => max(30, (int) env('COUPON_AUTHORIZATION_OTP_RESEND_SECONDS', 60)),

    /**
     * Minutos de validez del token de verificación OTP tras aprobar en bandeja.
     */
    'authorization_otp_verification_ttl_minutes' => max(1, (int) env('COUPON_AUTHORIZATION_OTP_VERIFICATION_TTL', 5)),
];
