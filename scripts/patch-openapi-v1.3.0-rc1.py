#!/usr/bin/env python3
"""Patch akubica-openapi copy to v1.3.0-rc1 contract."""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
p = ROOT / "docs/Akubica/akubica-openapi-v1.3.0-rc1.yaml"
if not p.exists():
    src = ROOT / "docs/Akubica/akubica-openapi.yaml"
    p.write_text(src.read_text(encoding="utf-8"), encoding="utf-8")

text = p.read_text(encoding="utf-8")
text = text.replace("  version: 1.2.3", "  version: 1.3.0-rc1", 1)
text = text.replace(
    "- **61 operaciones** documentadas",
    "- **67 operaciones** documentadas (misma matriz de operationId que v1.2.3; contrato RC1 Leo)",
    1,
)

if "  - name: Memberships" not in text.split("paths:")[0]:
    text = text.replace(
        "  - name: User\n    description: Perfil, contactos, direcciones, tax profiles\n\nsecurity:",
        "  - name: User\n    description: Perfil, contactos, direcciones, tax profiles\n"
        "  - name: Memberships\n    description: Planes y estado de membresía médica\n"
        "  - name: Integrations\n    description: Integraciones sandbox ATIX (reconocimiento conversacional)\n\n"
        "security:",
        1,
    )

if "ForbiddenError:" not in text:
    text = text.replace(
        "    StepUpForbidden:",
        "    ForbiddenError:\n      $ref: '#/components/responses/Forbidden'\n"
        "    TooManyRequestsError:\n      $ref: '#/components/responses/TooManyRequests'\n"
        "    AccountContextRequired:\n      description: ACCOUNT_CONTEXT_REQUIRED / ACCOUNT_CONTEXT_EXPIRED\n"
        "      headers:\n        X-Correlation-ID:\n          $ref: '#/components/headers/X-Correlation-ID'\n"
        "      content:\n        application/json:\n          schema:\n            $ref: '#/components/schemas/ApiErrorResponse'\n"
        "    AtixUnauthorized:\n      description: ATIX signature, timestamp or integration key invalid\n"
        "      headers:\n        X-Correlation-ID:\n          $ref: '#/components/headers/X-Correlation-ID'\n"
        "      content:\n        application/json:\n          schema:\n            $ref: '#/components/schemas/ApiErrorResponse'\n"
        "    StepUpForbidden:",
        1,
    )

if "atixHmac:" not in text:
    text = text.replace(
        "    StepUpGrantHeader:",
        "    atixHmac:\n      type: apiKey\n      in: header\n      name: X-Signature\n"
        "      description: |\n        Firma HMAC-SHA256 hex (64 chars) sobre la cadena canonica:\n"
        "        METHOD, ruta absoluta /api/v1/..., timestamp, nonce, SHA-256 del cuerpo JSON.\n"
        "        Requiere ademas X-Integration-Key-Id, X-Timestamp, X-Nonce e Idempotency-Key.\n"
        "    StepUpGrantHeader:",
        1,
    )

text = re.sub(
    r"\n      x-proposed-v1-3-0-context:[\s\S]*?(?=\n      description:)",
    "\n",
    text,
)

text = text.replace(
    """      description: |
        **P0-A ON:** body `{ "phone": "+52..." }` → `202` + `OtpChallenge` (SMS).
        Para reconocimiento conversacional puede incluir `conversation_id`, `channel_event_id`, `channel` y `personalization.allowed`.
        Teléfono inexistente → misma forma `202` (señuelo; sin SMS).
        **Legacy OFF:** body `{ "email" }` → `200` genérico (anti-enumeración).
        Soporta `Idempotency-Key` opcional (fase 1) cuando el flag está ON.""",
    """      description: |
        **P0-A ON:** body `{ "phone": "+52..." }` → `202` + `OtpChallenge` (SMS) cuando no hay contexto conversacional.

        **Flujo Leo RC1 (contextual):** requiere reconocimiento previo via `POST /integrations/atix/phone-lookups`.
        Debe incluir `conversation_id`, `channel_event_id`, `channel`, `audience` y opcionalmente `personalization`.
        Sin contexto pre-OTP activo para la misma vinculacion → `422 ACCOUNT_CONTEXT_REQUIRED`.
        Nunca devuelve `account_context_token`.

        Teléfono inexistente → misma forma `202` (señuelo; sin SMS). **Legacy OFF:** `{ "email" }` → `200`.""",
    1,
)

text = text.replace(
    """              contextual-proposal-v1-3:
                summary: Ejemplo sintético de propuesta; no contiene secretos.
                value:
                  phone: '+520000000000'
                  conversation_id: conversation-example
                  channel_event_id: event-example
                  channel: channel-pending-atix
                  audience: audience-pending-atix
                  personalization:
                    allowed: true""",
    """              contextual-rc1:
                summary: Solicitud OTP tras phone lookup ATIX (valores sinteticos).
                value:
                  phone: '+525500000000'
                  conversation_id: conv-sandbox-example
                  channel_event_id: evt-sandbox-example
                  channel: whatsapp
                  audience: leo
                  personalization:
                    allowed: true""",
    1,
)

# request-code 422: add account context (first occurrence only)
text = text.replace(
    """        '422':
          $ref: '#/components/responses/ValidationError'
        '429':
          $ref: '#/components/responses/TooManyRequests'
        '409':
          $ref: '#/components/responses/IdempotencyConflict'
        '503':
          $ref: '#/components/responses/DeliveryOrConfigFailed'

          headers:
            X-Correlation-ID:
              $ref: '#/components/headers/X-Correlation-ID'
  /auth/login/verify-code:""",
    """        '422':
          description: VALIDATION_ERROR / ACCOUNT_CONTEXT_REQUIRED
          headers:
            X-Correlation-ID:
              $ref: '#/components/headers/X-Correlation-ID'
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ApiErrorResponse'
        '429':
          $ref: '#/components/responses/TooManyRequestsError'
        '409':
          $ref: '#/components/responses/IdempotencyConflict'
        '503':
          $ref: '#/components/responses/DeliveryOrConfigFailed'

          headers:
            X-Correlation-ID:
              $ref: '#/components/headers/X-Correlation-ID'
  /auth/login/verify-code:""",
    1,
)

text = text.replace(
    """      description: |
        **P0-A:** `{ "challenge_id", "code" }` → token + user.
        En modo contextual conversacional, el OTP se verifica sin crear sesión Sanctum;
        la respuesta contiene únicamente el estado de verificación y, si fue permitido,
        el primer nombre. El `account_context_token` nunca se expone.
        **Legacy:** `{ "email", "code" }`.
        `expires_in` refleja TTL efectivo (≈10800 s con Sanctum 3h ON).""",
    """      description: |
        **P0-A:** `{ "challenge_id", "code" }`.

        **Contextual RC1:** valida OTP y eleva el mismo `AccountContext` pre-OTP.
        Emite Bearer Sanctum real (`token`, `token_type`, `expires_in`, `expires_at`, `user`)
        mas `otp_verified=true` y `assurance_level=otp_verified`. No expone `account_context_token`.

        **Login normal:** misma respuesta Bearer sin campos contextuales. **Legacy:** `{ "email", "code" }`.""",
    1,
)

text = text.replace(
    "                          - $ref: '#/components/schemas/ContactRecognitionResponse'",
    "                          - $ref: '#/components/schemas/ContextualOtpVerifiedTokenResponse'",
    1,
)

text = text.replace(
    """      security: []
      description: |
        Endpoint sandbox de FAMEDIC para reconocimiento conversacional. Requiere
        HMAC-SHA256 sobre `METHOD`, ruta, timestamp, nonce y hash SHA-256 del cuerpo,
        separados por saltos de línea. No devuelve `account_context_token` ni crea
        una sesión Bearer. La respuesta permite únicamente iniciar OTP y, si se
        solicitó, personalizar con el primer nombre.""",
    """      security:
        - atixHmac: []
      description: |
        Reconocimiento conversacional limitado (RC1). Requiere firma HMAC-SHA256.
        **No** crea OTP, **no** envia SMS, **no** emite Bearer.
        Cuenta elegible → `recognition_status=recognized_unverified` y personalizacion permitida.
        Desconocida → `recognition_status=unknown` (anti-enumeracion).
        Crea o reutiliza contexto pre-OTP interno; nunca devuelve `account_context_token`.""",
    1,
)

text = text.replace(
    "          description: Reconocimiento anti-enumeracion y OTP iniciado si corresponde",
    "          description: Reconocimiento limitado sin OTP",
    1,
)
text = text.replace(
    "            X-Correlation-ID: {$ref: '#/components/headers/X-Correlation-ID'}",
    "            X-Correlation-ID:\n              $ref: '#/components/headers/X-Correlation-ID'",
    1,
)
text = text.replace(
    "        '401': {$ref: '#/components/responses/Unauthenticated'}",
    "        '401':\n          $ref: '#/components/responses/AtixUnauthorized'",
    1,
)

old_atix_resp = """    AtixPhoneLookupResponse:
      type: object
      required: [success, data]
      properties:
        success: {type: boolean, const: true}
        data:
          type: object
          required: [requires_otp, challenge_id, purpose, channel, destination_masked, expires_at, resend_available_at, personalization]
          properties:
            requires_otp: {type: boolean, const: true}
            challenge_id: {type: string}
            purpose: {type: string, example: akubica_login}
            channel: {type: string, example: sms}
            destination_masked: {type: string}
            expires_at: {type: string, format: date-time}
            resend_available_at: {type: string, format: date-time}
            personalization:
              type: object
              required: [allowed]
              properties:
                allowed: {type: boolean}
                first_name: {type: string, nullable: true}"""

new_atix_resp = """    AtixPhoneLookupResponse:
      allOf:
        - $ref: '#/components/schemas/ApiSuccessResponse'
        - type: object
          properties:
            data:
              oneOf:
                - $ref: '#/components/schemas/PhoneLookupRecognizedData'
                - $ref: '#/components/schemas/PhoneLookupUnknownData'
    PhoneLookupRecognizedData:
      type: object
      required: [recognition_status, assurance_level, personalization]
      additionalProperties: false
      properties:
        recognition_status:
          type: string
          const: recognized_unverified
        assurance_level:
          type: string
          const: recognized_unverified
        personalization:
          type: object
          required: [allowed]
          additionalProperties: false
          properties:
            allowed: {type: boolean}
            first_name: {type: string, nullable: true}
    PhoneLookupUnknownData:
      type: object
      required: [recognition_status, assurance_level, personalization]
      additionalProperties: false
      properties:
        recognition_status:
          type: string
          const: unknown
        assurance_level:
          type: string
          const: none
        personalization:
          type: object
          required: [allowed]
          additionalProperties: false
          properties:
            allowed: {type: boolean, const: false}"""

if old_atix_resp in text:
    text = text.replace(old_atix_resp, new_atix_resp)

old_ctx = """    ContactRecognitionResponse:
      type: object
      required: [otp_verified, personalization]
      additionalProperties: false
      properties:
        otp_verified:
          type: boolean
          const: true
        personalization:
          type: object
          required: [allowed]
          additionalProperties: false
          properties:
            allowed:
              type: boolean
            first_name:
              type: string
              description: Solo aparece cuando allowed=true y el OTP fue validado."""

new_ctx = """    ContextualOtpVerifiedTokenResponse:
      allOf:
        - $ref: '#/components/schemas/TokenResponse'
        - type: object
          required: [otp_verified, assurance_level, personalization]
          additionalProperties: false
          properties:
            otp_verified:
              type: boolean
              const: true
            assurance_level:
              type: string
              const: otp_verified
            personalization:
              type: object
              required: [allowed]
              additionalProperties: false
              properties:
                allowed: {type: boolean}
                first_name: {type: string, nullable: true}
    ContactRecognitionResponse:
      $ref: '#/components/schemas/ContextualOtpVerifiedTokenResponse'"""

if "ContextualOtpVerifiedTokenResponse" not in text and old_ctx in text:
    text = text.replace(old_ctx, new_ctx)

text = text.replace(
    """        '200':
          description: Totales
          headers:
            X-Correlation-ID:
              $ref: '#/components/headers/X-Correlation-ID'
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ApiSuccessResponse'
        '401':
          $ref: '#/components/responses/Unauthenticated'

          headers:
            X-Correlation-ID:
              $ref: '#/components/headers/X-Correlation-ID'
        '403':
          $ref: '#/components/responses/ForbiddenError'
  /cart/items:""",
    """        '200':
          description: Totales
          headers:
            X-Correlation-ID:
              $ref: '#/components/headers/X-Correlation-ID'
          content:
            application/json:
              schema:
                oneOf:
                  - $ref: '#/components/schemas/MedicalServiceCartTotalsResponse'
                  - $ref: '#/components/schemas/ApiSuccessResponse'
        '401':
          $ref: '#/components/responses/Unauthenticated'

          headers:
            X-Correlation-ID:
              $ref: '#/components/headers/X-Correlation-ID'
        '403':
          $ref: '#/components/responses/ForbiddenError'
  /cart/items:""",
    1,
)

text = text.replace(
    """        '200':
          description: Carrito
          headers:
            X-Correlation-ID:
              $ref: '#/components/headers/X-Correlation-ID'
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ApiSuccessResponse'
        '401':
          $ref: '#/components/responses/Unauthenticated'
        '403':
          $ref: '#/components/responses/ForbiddenError'
    delete:""",
    """        '200':
          description: Carrito
          headers:
            X-Correlation-ID:
              $ref: '#/components/headers/X-Correlation-ID'
          content:
            application/json:
              schema:
                oneOf:
                  - $ref: '#/components/schemas/MedicalServiceCartResponse'
                  - $ref: '#/components/schemas/ApiSuccessResponse'
        '401':
          $ref: '#/components/responses/Unauthenticated'
        '403':
          $ref: '#/components/responses/ForbiddenError'
    delete:""",
    1,
)

text = text.replace(
    """        '200':
          description: Payload prepare
          headers:
            X-Correlation-ID:
              $ref: '#/components/headers/X-Correlation-ID'
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ApiSuccessResponse'
        '401':
          $ref: '#/components/responses/Unauthenticated'

          headers:
            X-Correlation-ID:
              $ref: '#/components/headers/X-Correlation-ID'
        '403':
          $ref: '#/components/responses/ForbiddenError'
  /checkout/draft:""",
    """        '200':
          description: Payload prepare
          headers:
            X-Correlation-ID:
              $ref: '#/components/headers/X-Correlation-ID'
          content:
            application/json:
              schema:
                oneOf:
                  - $ref: '#/components/schemas/MedicalServiceCheckoutPrepareResponse'
                  - $ref: '#/components/schemas/ApiSuccessResponse'
        '401':
          $ref: '#/components/responses/Unauthenticated'

          headers:
            X-Correlation-ID:
              $ref: '#/components/headers/X-Correlation-ID'
        '403':
          $ref: '#/components/responses/ForbiddenError'
  /checkout/draft:""",
    1,
)

insert_schemas = """
    MedicalServiceCartTotals:
      type: object
      required: [service_line, subtotal_cents, discount_cents, credit_applied_cents, total_cents, currency, item_count]
      additionalProperties: false
      properties:
        service_line:
          type: string
          const: medical_services
        subtotal_cents: {type: integer, minimum: 0}
        discount_cents: {type: integer, minimum: 0}
        credit_applied_cents: {type: integer, minimum: 0}
        total_cents: {type: integer, minimum: 0}
        currency: {type: string, const: MXN}
        item_count: {type: integer, minimum: 0}

    MedicalServiceCartTotalsResponse:
      allOf:
        - $ref: '#/components/schemas/ApiSuccessResponse'
        - type: object
          properties:
            data:
              $ref: '#/components/schemas/MedicalServiceCartTotals'

    MedicalServiceCheckoutPrepareData:
      type: object
      required: [service_line, cart, requirements, warnings, assistant_can_pay, can_continue_on_platform]
      additionalProperties: false
      properties:
        service_line:
          type: string
          const: medical_services
        cart:
          $ref: '#/components/schemas/MedicalServiceCart'
        requirements:
          type: object
          additionalProperties: true
        warnings:
          type: array
          items: {type: string}
        assistant_can_pay:
          type: boolean
          const: false
          description: Leo/ATIX no puede pagar ni generar payment links.
        can_continue_on_platform:
          type: boolean
          description: El usuario puede continuar el pago en FAMEDIC cuando el carrito esta listo.

    MedicalServiceCheckoutPrepareResponse:
      allOf:
        - $ref: '#/components/schemas/ApiSuccessResponse'
        - type: object
          properties:
            data:
              $ref: '#/components/schemas/MedicalServiceCheckoutPrepareData'

"""

if "MedicalServiceCheckoutPrepareResponse" not in text:
    text = text.replace("    LaboratoryBrand:", insert_schemas + "    LaboratoryBrand:", 1)

if "LoginRequestCodeContextual:" not in text:
    text = text.replace(
        "    LoginRequestCodeSms:",
        "    LoginRequestCodeContextual:\n      allOf:\n        - $ref: '#/components/schemas/LoginRequestCodeSms'\n"
        "        - type: object\n          required: [conversation_id, channel_event_id, channel, audience]\n"
        "          properties:\n            conversation_id: {type: string, maxLength: 128}\n"
        "            channel_event_id: {type: string, maxLength: 128}\n            channel: {type: string, maxLength: 32}\n"
        "            audience: {type: string, maxLength: 64}\n            personalization:\n"
        "              type: object\n              properties:\n                allowed: {type: boolean}\n\n"
        "    LoginRequestCodeSms:",
        1,
    )
    text = text.replace(
        "              oneOf:\n                - $ref: '#/components/schemas/LoginRequestCodeSms'\n"
        "                - $ref: '#/components/schemas/LoginRequestCodeLegacy'",
        "              oneOf:\n                - $ref: '#/components/schemas/LoginRequestCodeSms'\n"
        "                - $ref: '#/components/schemas/LoginRequestCodeContextual'\n"
        "                - $ref: '#/components/schemas/LoginRequestCodeLegacy'",
        1,
    )

p.write_text(text, encoding="utf-8")
print(f"Wrote {p}")
