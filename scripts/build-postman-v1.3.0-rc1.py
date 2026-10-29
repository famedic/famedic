#!/usr/bin/env python3
"""Build Famedic-Akubica-v1.3.0-rc1 Postman collection."""
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "docs/Akubica/v1.3.0-rc1/Famedic-Akubica-v1.3.0-rc1.postman_collection.json"
OUT.parent.mkdir(parents=True, exist_ok=True)

PARSE_RESPONSE = [
    "var responsePayload = {};",
    "try { responsePayload = pm.response.json(); } catch (parseErr) { responsePayload = {}; }",
]

COMMON_TESTS_TAIL = [
    "const expectedStatus = (pm.variables.get('expected_status') || '200').split(',').map(s => parseInt(s.trim(), 10));",
    "pm.test('HTTP status esperado', function () { pm.expect(pm.response.code).to.be.oneOf(expectedStatus); });",
    "const sentCorrelationId = pm.variables.get('request_correlation_id');",
    "pm.test('X-Correlation-ID presente y coherente con la peticion', function () {",
    "  const hdr = pm.response.headers.get('X-Correlation-ID');",
    "  pm.expect(hdr).to.be.ok;",
    "  pm.expect(hdr).to.eql(sentCorrelationId);",
    "});",
    "if (responsePayload.error) {",
    "  pm.test('error.code presente', function () { pm.expect(responsePayload.error.code).to.be.a('string'); });",
    "  pm.test('error.correlation_id coherente', function () {",
    "    pm.expect(responsePayload.error.correlation_id).to.eql(sentCorrelationId);",
    "  });",
    "  if (pm.variables.get('expected_error_code')) {",
    "    pm.test('error.code esperado', function () { pm.expect(responsePayload.error.code).to.eql(pm.variables.get('expected_error_code')); });",
    "  }",
    "  pm.test('error.retryable es boolean', function () { pm.expect(responsePayload.error.retryable).to.be.a('boolean'); });",
    "}",
    "const rawResponseText = pm.response.text();",
    "pm.test('sin account_context_token', function () { pm.expect(rawResponseText).to.not.include('account_context_token'); });",
    "pm.test('sin payment link ni pagos', function () {",
    "  pm.expect(rawResponseText.toLowerCase()).to.not.include('payment_link');",
    "  pm.expect(rawResponseText.toLowerCase()).to.not.include('stripe');",
    "});",
    "if (responsePayload.success === true && responsePayload.data) {",
    "  pm.test('schema success envelope', function () {",
    "    pm.expect(responsePayload.success).to.eql(true);",
    "    pm.expect(responsePayload.data).to.be.an('object');",
    "  });",
    "}",
]

COMMON_TESTS = [*PARSE_RESPONSE, *COMMON_TESTS_TAIL]

SKIP_FIXTURE_TEST = [
    *PARSE_RESPONSE,
    "pm.test('[fixture] request omitido — requisito previo no cumplido', function () {",
    "  pm.expect(pm.variables.get('skip_fixture_reason')).to.be.a('string');",
    "});",
]


def t(status: str, *extra: str) -> list[str]:
    return [
        f"pm.variables.set('expected_status', '{status}');",
        *PARSE_RESPONSE,
        *extra,
        *COMMON_TESTS_TAIL,
    ]


PREREQUEST_BASE = [
    "const uuid = () => pm.variables.replaceIn('{{$guid}}');",
    "const requestCorrelationId = uuid();",
    "pm.variables.set('correlation_id', requestCorrelationId);",
    "pm.variables.set('request_correlation_id', requestCorrelationId);",
    "pm.variables.unset('skip_fixture_reason');",
    "if (!pm.variables.get('skip_fresh_idempotency')) {",
    "  pm.variables.set('idempotency_key', 'rc1-' + uuid());",
    "}",
    "pm.variables.unset('force_atix_timestamp');",
    "pm.variables.unset('force_atix_nonce');",
    "pm.variables.unset('force_atix_signature');",
]

PREREQUEST_ATIX_SIGN = [
    "const reqUrl = pm.request.url.toString();",
    "if (reqUrl.includes('/integrations/atix/phone-lookups')) {",
    "  const timestamp = pm.variables.get('force_atix_timestamp') || Math.floor(Date.now() / 1000).toString();",
    "  const nonce = pm.variables.get('force_atix_nonce') || ('n-' + uuid());",
    "  const secret = pm.environment.get('atix_hmac_secret') || pm.collectionVariables.get('atix_hmac_secret');",
    "  const hmacBodyRaw = pm.request.body && pm.request.body.raw ? pm.variables.replaceIn(pm.request.body.raw) : '';",
    "  const bodyHash = CryptoJS.SHA256(hmacBodyRaw).toString(CryptoJS.enc.Hex);",
    "  const canonical = ['POST', '/api/v1/integrations/atix/phone-lookups', timestamp, nonce, bodyHash].join('\\n');",
    "  pm.variables.set('atix_timestamp', timestamp);",
    "  pm.variables.set('atix_nonce', nonce);",
    "  let sig = pm.variables.get('force_atix_signature');",
    "  if (!sig) { sig = CryptoJS.HmacSHA256(canonical, secret).toString(CryptoJS.enc.Hex); }",
    "  pm.variables.set('atix_signature', sig);",
    "}",
]

REQUIRE_ACCESS_TOKEN_PRE = [
    "const accessToken = pm.environment.get('access_token') || pm.collectionVariables.get('access_token');",
    "if (!accessToken) {",
    "  pm.variables.set('skip_fixture_reason', 'sin access_token (OTP/Bearer pendiente)');",
    "  if (pm.execution && pm.execution.skipRequest) { pm.execution.skipRequest(); }",
    "}",
]

REQUIRE_OTP_PRE = [
    "const otpValue = pm.environment.get('otp_code') || pm.collectionVariables.get('otp_code');",
    "if (!otpValue) {",
    "  pm.variables.set('skip_fixture_reason', 'OTP_CODE no configurado');",
    "  if (pm.execution && pm.execution.skipRequest) { pm.execution.skipRequest(); }",
    "}",
]

REQUIRE_CART_ITEM_PRE = [
    *REQUIRE_ACCESS_TOKEN_PRE,
    "const cartItemId = pm.environment.get('medical_cart_item_id') || pm.collectionVariables.get('medical_cart_item_id');",
    "if (!cartItemId) {",
    "  pm.variables.set('skip_fixture_reason', 'medical_cart_item_id no creado');",
    "  if (pm.execution && pm.execution.skipRequest) { pm.execution.skipRequest(); }",
    "}",
]


def hdr(extra=None):
    h = [
        {"key": "Accept", "value": "application/json"},
        {"key": "X-Correlation-ID", "value": "{{correlation_id}}"},
    ]
    if extra:
        h.extend(extra)
    return h


def atix_body(phone_var="phone", conv_suffix="", personalization="true"):
    return {
        "mode": "raw",
        "raw": (
            '{\n  "phone": "{{%s}}",\n  "conversation_id": "{{conversation_id}}%s",\n'
            '  "channel_event_id": "{{channel_event_id}}%s",\n  "channel": "{{channel}}",\n'
            '  "audience": "{{audience}}",\n  "personalization": {"allowed": %s}\n}'
        )
        % (phone_var, conv_suffix, conv_suffix, personalization),
    }


def atix_item(name, body, test_exec, pre_extra=None):
    pre = PREREQUEST_BASE + (pre_extra or []) + PREREQUEST_ATIX_SIGN
    return {
        "name": name,
        "event": [
            {"listen": "prerequest", "script": {"type": "text/javascript", "exec": pre}},
            {"listen": "test", "script": {"type": "text/javascript", "exec": test_exec}},
        ],
        "request": {
            "method": "POST",
            "header": hdr(
                [
                    {"key": "Content-Type", "value": "application/json"},
                    {"key": "X-Integration-Key-Id", "value": "{{atix_integration_key_id}}"},
                    {"key": "X-Timestamp", "value": "{{atix_timestamp}}"},
                    {"key": "X-Nonce", "value": "{{atix_nonce}}"},
                    {"key": "X-Signature", "value": "{{atix_signature}}"},
                    {"key": "Idempotency-Key", "value": "{{idempotency_key}}"},
                ]
            ),
            "body": body,
            "url": "{{base_url}}/integrations/atix/phone-lookups",
        },
    }


def http_item(name, method, path, *, query=None, body=None, auth=False, idem=False, tests=None, pre_extra=None):
    headers = hdr()
    if auth:
        headers.append({"key": "Authorization", "value": "Bearer {{access_token}}"})
    if idem:
        headers.append({"key": "Idempotency-Key", "value": "{{idempotency_key}}"})
    if body:
        headers.append({"key": "Content-Type", "value": "application/json"})
    url = "{{base_url}}/" + path
    if query:
        url += "?" + query
    pre = PREREQUEST_BASE + (pre_extra or [])
    test_exec = tests or COMMON_TESTS
    ev = [
        {"listen": "prerequest", "script": {"type": "text/javascript", "exec": pre}},
        {"listen": "test", "script": {"type": "text/javascript", "exec": test_exec}},
    ]
    req = {"method": method, "header": headers, "url": url}
    if body:
        req["body"] = {"mode": "raw", "raw": body}
    return {"name": name, "event": ev, "request": req}


def fixture_or(test_exec):
    return [
        "if (pm.variables.get('skip_fixture_reason')) {",
        *SKIP_FIXTURE_TEST,
        "} else {",
        *test_exec,
        "}",
    ]


lookup_ok_tests = t(
    "202",
    "pm.test('recognition_status reconocido', function(){ pm.expect(responsePayload.data.recognition_status).to.eql('recognized_unverified'); });",
    "pm.test('assurance_level', function(){ pm.expect(responsePayload.data.assurance_level).to.eql('recognized_unverified'); });",
    "pm.test('sin OTP en lookup', function(){ pm.expect(responsePayload.data).to.not.have.property('challenge_id'); });",
    "pm.collectionVariables.set('contextual_conversation_id', pm.collectionVariables.get('conversation_id'));",
    "pm.collectionVariables.set('contextual_channel_event_id', pm.collectionVariables.get('channel_event_id'));",
)

lookup_unknown_tests = t(
    "202",
    "pm.test('unknown anti-enumeracion', function(){ pm.expect(responsePayload.data.recognition_status).to.eql('unknown'); });",
    "pm.test('assurance none', function(){ pm.expect(responsePayload.data.assurance_level).to.eql('none'); });",
)

collection = {
    "info": {
        "name": "Famedic Akubica API v1.3.0-rc1",
        "version": "1.3.0-rc1",
        "description": "RC1 Leo. Telefonos E.164 via entorno (RC1_STAGING_PHONE). Sin secretos en archivo.",
        "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
    },
    "event": [
        {"listen": "prerequest", "script": {"type": "text/javascript", "exec": PREREQUEST_BASE}},
    ],
    "variable": [
        {"key": "base_url", "value": ""},
        {"key": "access_token", "value": ""},
        {"key": "atix_integration_key_id", "value": ""},
        {"key": "atix_hmac_secret", "value": ""},
        {"key": "correlation_id", "value": ""},
        {"key": "request_correlation_id", "value": ""},
        {"key": "idempotency_key", "value": ""},
        {"key": "idempotency_replay_key", "value": ""},
        {"key": "atix_replay_nonce", "value": ""},
        {"key": "phone", "value": ""},
        {"key": "unknown_phone", "value": ""},
        {"key": "conversation_id", "value": "synthetic-conv-rc1-staging"},
        {"key": "channel_event_id", "value": "synthetic-event-rc1-staging"},
        {"key": "channel", "value": "whatsapp"},
        {"key": "audience", "value": "leo"},
        {"key": "contextual_conversation_id", "value": ""},
        {"key": "contextual_channel_event_id", "value": ""},
        {"key": "membership_plan_code", "value": "basic_annual"},
        {"key": "coupon_code", "value": "synthetic-coupon-rc1-staging"},
        {"key": "challenge_id", "value": ""},
        {"key": "otp_code", "value": ""},
        {"key": "medical_cart_item_id", "value": ""},
        {"key": "brand", "value": "olab"},
        {"key": "laboratory_test_id", "value": "1"},
        {"key": "expected_status", "value": "200"},
        {"key": "expected_error_code", "value": ""},
        {"key": "skip_fresh_idempotency", "value": ""},
    ],
    "item": [
        {
            "name": "01 - ATIX phone lookup",
            "item": [
                atix_item("1 atixPhoneLookup reconocido sin OTP", atix_body(), lookup_ok_tests),
                atix_item("2 atixPhoneLookup telefono desconocido", atix_body("unknown_phone", "-unknown"), lookup_unknown_tests),
                atix_item(
                    "3 atixPhoneLookup firma invalida",
                    atix_body("phone"),
                    t("401"),
                    pre_extra=[
                        "pm.variables.set('force_atix_signature', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');",
                    ],
                ),
                atix_item(
                    "4 atixPhoneLookup timestamp expirado",
                    atix_body("phone", "-expired", "false"),
                    t("401"),
                    pre_extra=["pm.variables.set('force_atix_timestamp', '946684800');"],
                ),
                atix_item(
                    "5a atixPhoneLookup priming nonce fijo",
                    atix_body("phone", "-nonce-replay-pair"),
                    t("202"),
                    pre_extra=[
                        "pm.collectionVariables.set('atix_replay_nonce', 'rc1-nonce-' + uuid());",
                        "pm.variables.set('force_atix_nonce', pm.collectionVariables.get('atix_replay_nonce'));",
                    ],
                ),
                atix_item(
                    "5b atixPhoneLookup nonce replay",
                    atix_body("phone", "-nonce-replay-pair"),
                    t("409"),
                    pre_extra=[
                        "pm.variables.set('force_atix_nonce', pm.collectionVariables.get('atix_replay_nonce'));",
                    ],
                ),
                atix_item(
                    "6a atixPhoneLookup priming idempotency fija",
                    atix_body("phone", "-idem-replay-pair"),
                    t("202"),
                    pre_extra=[
                        "pm.collectionVariables.set('idempotency_replay_key', 'rc1-idem-' + uuid());",
                        "pm.variables.set('skip_fresh_idempotency', '1');",
                        "pm.variables.set('idempotency_key', pm.collectionVariables.get('idempotency_replay_key'));",
                    ],
                ),
                atix_item(
                    "6b atixPhoneLookup idempotency replay",
                    atix_body("phone", "-idem-replay-pair"),
                    t("409"),
                    pre_extra=[
                        "pm.variables.set('skip_fresh_idempotency', '1');",
                        "pm.variables.set('idempotency_key', pm.collectionVariables.get('idempotency_replay_key'));",
                    ],
                ),
            ],
        },
        {
            "name": "02 - Auth contextual RC1",
            "item": [
                http_item(
                    "7 authLoginRequestCode contextual",
                    "POST",
                    "auth/login/request-code",
                    body='{\n  "phone": "{{phone}}",\n  "conversation_id": "{{contextual_conversation_id}}",\n  "channel_event_id": "{{contextual_channel_event_id}}",\n  "channel": "{{channel}}",\n  "audience": "{{audience}}",\n  "personalization": {"allowed": true}\n}',
                    idem=True,
                    pre_extra=[
                        "if (!pm.collectionVariables.get('contextual_conversation_id')) {",
                        "  pm.collectionVariables.set('contextual_conversation_id', pm.collectionVariables.get('conversation_id'));",
                        "  pm.collectionVariables.set('contextual_channel_event_id', pm.collectionVariables.get('channel_event_id'));",
                        "}",
                    ],
                    tests=t(
                        "202",
                        "const reqConversationId = pm.variables.replaceIn('{{contextual_conversation_id}}');",
                        "const reqChannelEventId = pm.variables.replaceIn('{{contextual_channel_event_id}}');",
                        "const reqChannel = pm.variables.replaceIn('{{channel}}');",
                        "const reqAudience = pm.variables.replaceIn('{{audience}}');",
                        "pm.collectionVariables.set('contextual_conversation_id', reqConversationId);",
                        "pm.collectionVariables.set('contextual_channel_event_id', reqChannelEventId);",
                        "pm.collectionVariables.set('contextual_channel', reqChannel);",
                        "pm.collectionVariables.set('contextual_audience', reqAudience);",
                        "pm.test('challenge_id', function(){ pm.expect(responsePayload.data.challenge_id).to.be.a('string'); });",
                        "pm.collectionVariables.set('challenge_id', responsePayload.data.challenge_id);",
                    ),
                ),
                http_item(
                    "8 authLoginVerifyCode invalido",
                    "POST",
                    "auth/login/verify-code",
                    body='{\n  "challenge_id": "{{challenge_id}}",\n  "code": "000000"\n}',
                    tests=t("422"),
                ),
                http_item(
                    "9 authLoginVerifyCode valido contextual",
                    "POST",
                    "auth/login/verify-code",
                    body='{\n  "challenge_id": "{{challenge_id}}",\n  "code": "{{otp_code}}"\n}',
                    pre_extra=REQUIRE_OTP_PRE,
                    tests=fixture_or(
                        t(
                            "200",
                            "pm.test('token_type Bearer', function(){ pm.expect(responsePayload.data.token_type).to.eql('Bearer'); });",
                            "pm.test('otp_verified', function(){ pm.expect(responsePayload.data.otp_verified).to.eql(true); });",
                            "pm.test('assurance_level otp_verified', function(){ pm.expect(responsePayload.data.assurance_level).to.eql('otp_verified'); });",
                            "pm.collectionVariables.set('access_token', responsePayload.data.token);",
                            "pm.test('schema Bearer contextual', function(){ pm.expect(responsePayload.data).to.include.keys(['token','token_type','expires_in','expires_at','user','otp_verified','assurance_level']); });",
                        )
                    ),
                ),
                http_item(
                    "10 Captura Bearer (membershipStatusShow)",
                    "GET",
                    "memberships/status",
                    auth=True,
                    pre_extra=REQUIRE_ACCESS_TOKEN_PRE,
                    tests=fixture_or(t("200", "pm.test('membership schema', function(){ pm.expect(responsePayload.data).to.have.property('status'); });")),
                ),
            ],
        },
        {
            "name": "03 - Carrito medical_services",
            "item": [
                http_item(
                    "11 cartItemsStore basic_annual",
                    "POST",
                    "cart/items",
                    body='{\n  "service_line": "medical_services",\n  "item_type": "membership",\n  "membership_plan_code": "{{membership_plan_code}}"\n}',
                    auth=True,
                    idem=True,
                    pre_extra=REQUIRE_ACCESS_TOKEN_PRE,
                    tests=fixture_or(
                        t(
                            "201,200",
                            "const itemId = responsePayload.data && (responsePayload.data.id || (responsePayload.data.items && responsePayload.data.items[0] && responsePayload.data.items[0].id));",
                            "if (itemId) { pm.collectionVariables.set('medical_cart_item_id', String(itemId)); }",
                        )
                    ),
                ),
                http_item(
                    "12 cartIndex medical_services",
                    "GET",
                    "cart",
                    query="service_line=medical_services",
                    auth=True,
                    pre_extra=REQUIRE_ACCESS_TOKEN_PRE,
                    tests=fixture_or(t("200")),
                ),
                http_item(
                    "13 cartTotals medical_services",
                    "GET",
                    "cart/totals",
                    query="service_line=medical_services",
                    auth=True,
                    pre_extra=REQUIRE_ACCESS_TOKEN_PRE,
                    tests=fixture_or(t("200")),
                ),
                http_item(
                    "14 medicalServiceBenefitApply cupon o saldo",
                    "POST",
                    "cart/medical-benefit",
                    body='{\n  "service_line": "medical_services",\n  "code": "{{coupon_code}}"\n}',
                    auth=True,
                    idem=True,
                    pre_extra=REQUIRE_ACCESS_TOKEN_PRE,
                    tests=fixture_or(t("200,404,409")),
                ),
                http_item(
                    "15 medicalServiceBenefitRemove",
                    "DELETE",
                    "cart/medical-benefit",
                    query="service_line=medical_services",
                    auth=True,
                    idem=True,
                    pre_extra=REQUIRE_ACCESS_TOKEN_PRE,
                    tests=fixture_or(t("200")),
                ),
                http_item(
                    "16 checkoutPrepare medical_services",
                    "GET",
                    "checkout/prepare",
                    query="service_line=medical_services",
                    auth=True,
                    pre_extra=REQUIRE_ACCESS_TOKEN_PRE,
                    tests=fixture_or(
                        t(
                            "200",
                            "pm.test('assistant_can_pay false', function(){ pm.expect(responsePayload.data.assistant_can_pay).to.eql(false); });",
                            "pm.test('can_continue_on_platform true', function(){ pm.expect(responsePayload.data.can_continue_on_platform).to.eql(true); });",
                        )
                    ),
                ),
                http_item(
                    "17 cartItemsDestroy membresia",
                    "DELETE",
                    "cart/items/{{medical_cart_item_id}}",
                    auth=True,
                    idem=True,
                    pre_extra=REQUIRE_CART_ITEM_PRE,
                    tests=fixture_or(t("200,404")),
                ),
                http_item(
                    "18 Aislamiento laboratorio vs medical_services",
                    "POST",
                    "cart/items",
                    body='{\n  "laboratory_test_id": {{laboratory_test_id}},\n  "brand": "{{brand}}"\n}',
                    auth=True,
                    idem=True,
                    pre_extra=REQUIRE_ACCESS_TOKEN_PRE,
                    tests=fixture_or(t("409,403,422")),
                ),
                http_item(
                    "19 Aislamiento farmacia (carrito pharmacy vacio)",
                    "GET",
                    "cart",
                    query="service_line=pharmacy&brand=olab",
                    auth=True,
                    pre_extra=REQUIRE_ACCESS_TOKEN_PRE,
                    tests=fixture_or(t("200,403,422")),
                ),
            ],
        },
    ],
}

OUT.write_text(json.dumps(collection, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
alt = ROOT / "postman/Famedic-Akubica-v1.3.0-rc1.postman_collection.json"
alt.write_text(OUT.read_text(encoding="utf-8"), encoding="utf-8")
print(f"Wrote {OUT} and {alt}")
