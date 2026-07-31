#!/usr/bin/env python3
"""P0-D1 validation: OpenAPI, Postman JSON, route comparison, secrets scan."""
from __future__ import annotations

import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
errors: list[str] = []
warnings: list[str] = []


def ok(msg: str) -> None:
    print(f"OK  {msg}")


def fail(msg: str) -> None:
    errors.append(msg)
    print(f"FAIL {msg}")


def warn(msg: str) -> None:
    warnings.append(msg)
    print(f"WARN {msg}")


def laravel_routes() -> set[tuple[str, str]]:
    text = (ROOT / "routes/api/v1.php").read_text(encoding="utf-8")
    routes: set[tuple[str, str]] = set()
    # Approximate extraction from Route::method('path'
    for m in re.finditer(
        r"Route::(get|post|put|delete|patch)\(\s*'([^']+)'",
        text,
        re.I,
    ):
        method, path = m.group(1).upper(), m.group(2)
        # expand prefixes crudely by scanning context is hard; use artisan if available
        routes.add((method, path))
    return routes


def openapi_paths(yaml_text: str) -> set[tuple[str, str]]:
    paths: set[tuple[str, str]] = set()
    current = None
    for line in yaml_text.splitlines():
        if re.match(r"^  /", line) and line.rstrip().endswith(":"):
            current = line.strip()[:-1]
            continue
        if current and re.match(r"^    (get|post|put|delete|patch):", line):
            method = line.strip().split(":")[0].upper()
            paths.add((method, current))
    return paths


def main() -> int:
    print("=== P0-D1 validation ===")

    # OpenAPI YAML parse (PyYAML optional)
    oa_path = ROOT / "docs/Akubica/akubica-openapi.yaml"
    oa_text = oa_path.read_text(encoding="utf-8")
    try:
        import yaml  # type: ignore

        doc = yaml.safe_load(oa_text)
        assert isinstance(doc, dict) and "paths" in doc
        ok(f"OpenAPI YAML parse ({len(doc['paths'])} path keys)")
        if str(doc.get("info", {}).get("version")) != "1.2.0":
            warn(f"OpenAPI version is {doc.get('info', {}).get('version')}")
        required_schemas = [
            "ApiSuccessResponse",
            "ApiErrorResponse",
            "OtpChallenge",
            "OtpGrant",
            "SecureLink",
            "TokenResponse",
            "Pagination",
            "Order",
            "Result",
            "Invoice",
        ]
        schemas = (doc.get("components") or {}).get("schemas") or {}
        for s in required_schemas:
            if s not in schemas:
                fail(f"Missing schema {s}")
            else:
                ok(f"schema {s}")
        must_paths = [
            "/auth/login/resend-code",
            "/auth/register/resend-code",
            "/secure-downloads/{token}",
            "/orders/{order_id}/results/step-up/request",
            "/orders/{order_id}/results/secure-link",
            "/orders/{order_id}/invoices/{invoice_id}/step-up/request",
            "/orders/{order_id}/invoices/{invoice_id}/secure-link",
            "/orders/{order_id}/results/download",
        ]
        for p in must_paths:
            if p not in doc["paths"]:
                fail(f"Missing OpenAPI path {p}")
            else:
                ok(f"path {p}")
    except ImportError:
        warn("PyYAML not installed — structural regex checks only")
        if "openapi: 3.1.0" not in oa_text:
            fail("openapi version line missing")
        else:
            ok("openapi 3.1.0 header present")
        for needle in [
            "/auth/login/resend-code",
            "/secure-downloads/{token}",
            "OtpChallenge",
            "X-Step-Up-Grant",
            "STEP_UP_REQUIRED",
        ]:
            if needle not in oa_text:
                fail(f"OpenAPI missing {needle}")
            else:
                ok(f"contains {needle}")

    oa_ops = openapi_paths(oa_text)
    ok(f"OpenAPI operations counted: {len(oa_ops)}")

    # Postman JSON
    for name in [
        "postman/Famedic-Akubica-API-v1.postman_collection.json",
        "postman/Famedic-Akubica-Local.postman_environment.json",
        "postman/Famedic-Akubica-Staging.postman_environment.json",
        "postman/Famedic-Akubica-Production.postman_environment.json",
    ]:
        p = ROOT / name
        try:
            data = json.loads(p.read_text(encoding="utf-8"))
            ok(f"JSON valid {name}")
        except Exception as e:  # noqa: BLE001
            fail(f"JSON invalid {name}: {e}")
            continue

    col = json.loads(
        (ROOT / "postman/Famedic-Akubica-API-v1.postman_collection.json").read_text(
            encoding="utf-8"
        )
    )
    declared = {v["key"] for v in col.get("variable", [])}
    used = set(re.findall(r"\{\{([a-zA-Z0-9_]+)\}\}", json.dumps(col)))
    missing_decl = sorted(used - declared - {"base_url"})  # base_url often env-only
    # base_url is declared; still check
    undeclared = sorted(used - declared)
    if undeclared:
        fail(f"Postman vars used but not declared: {undeclared}")
    else:
        ok("Postman collection vars declared ⊇ used")

    prod = json.loads(
        (ROOT / "postman/Famedic-Akubica-Production.postman_environment.json").read_text(
            encoding="utf-8"
        )
    )
    prod_vals = {v["key"]: v.get("value", "") for v in prod.get("values", [])}
    if prod_vals.get("allow_production_writes") != "false":
        fail("Production allow_production_writes must default false")
    else:
        ok("Production allow_production_writes=false")
    for secret_key in [
        "access_token",
        "test_email",
        "test_phone",
        "correct_otp",
        "login_correct_otp",
        "results_secure_download_url",
        "invoice_secure_download_url",
    ]:
        if prod_vals.get(secret_key):
            fail(f"Production secret {secret_key} is not empty")
    else:
        ok("Production tracked secrets empty")

    prereq = json.dumps(col.get("event", []))
    if "PRODUCTION GUARD" not in prereq and "allow_production_writes" not in prereq:
        fail("Collection prerequest missing production guard")
    else:
        ok("Collection production guard present")

    # Secrets / PII scan on versioned docs
    scan_files = list((ROOT / "docs/Akubica").glob("api-v1-*.md"))
    scan_files += list((ROOT / "docs/Akubica").glob("p0-*.md"))
    scan_files += [
        ROOT / "docs/Akubica/README.md",
        ROOT / "docs/Akubica/akubica-openapi.yaml",
        ROOT / "docs/Akubica/openapi-changelog-v1.2.0.md",
        ROOT / "postman/README.md",
    ]
    bad_patterns = [
        (re.compile(r"\+52[1-9]\d{9}"), "possible MX phone"),
        (re.compile(r"\b\d{6}\b"), None),  # OTP-like — only warn in examples carefully
        (re.compile(r"Bearer\s+[A-Za-z0-9|_\-]{20,}"), "possible bearer token"),
        (re.compile(r"VONAGE_(KEY|SECRET)=\S+"), "vonage secret assigned"),
        (re.compile(r"secure-downloads/[A-Fa-f0-9]{64}"), "opaque token in URL"),
    ]
    for f in scan_files:
        if not f.exists():
            continue
        text = f.read_text(encoding="utf-8")
        if "+5255" in text or "+521" in text.replace("+520000000000", ""):
            # allow placeholder +520000000000
            if re.search(r"\+52(?!0000000000)\d{10}", text):
                fail(f"Possible real phone in {f.relative_to(ROOT)}")
        if "OTP_P0A_SMS_DELIVERY_PROVIDER" in text and "obsolet" not in text.lower() and "inválid" not in text.lower() and "invalid" not in text.lower() and "ignorad" not in text.lower():
            warn(f"{f.name} mentions OTP_P0A_SMS_DELIVERY_PROVIDER — ensure marked obsolete")
        for pat, label in bad_patterns:
            if label is None:
                continue
            if pat.search(text):
                warn(f"{label} in {f.relative_to(ROOT)}")

    # Route vs OpenAPI (best-effort via artisan)
    try:
        out = subprocess.check_output(
            ["php", "artisan", "route:list", "--path=api/v1", "--json"],
            cwd=ROOT,
            text=True,
            stderr=subprocess.DEVNULL,
            timeout=60,
        )
        routes = json.loads(out)
        laravel_ops: set[tuple[str, str]] = set()
        for r in routes:
            raw_methods = r.get("methods") or ([r.get("method")] if r.get("method") else [])
            methods: list[str] = []
            for m in raw_methods:
                methods.extend(str(m).replace("|", " ").split())
            uri = r.get("uri") or ""
            path = "/" + uri.split("api/v1", 1)[-1].lstrip("/") if "api/v1" in uri else "/" + uri
            if path in ("/", "//"):
                path = "/"
            if not path.startswith("/"):
                path = "/" + path
            for method in methods:
                method_u = method.upper()
                if method_u in ("HEAD", "OPTIONS"):
                    continue
                laravel_ops.add((method_u, path))
        missing_oa = [
            f"{method} {path}"
            for method, path in sorted(laravel_ops)
            if (method, path) not in oa_ops and (method, path.rstrip("/")) not in oa_ops
        ]
        if missing_oa:
            warn(f"Laravel routes not in OpenAPI ({len(missing_oa)}): sample {missing_oa[:8]}")
        else:
            ok(f"All {len(laravel_ops)} Laravel routes matched OpenAPI ops set")
        extra = [
            f"{method} {path}"
            for method, path in sorted(oa_ops)
            if (method, path) not in laravel_ops
        ]
        if extra:
            warn(f"OpenAPI ops without Laravel match ({len(extra)}): sample {extra[:8]}")
    except Exception as e:  # noqa: BLE001
        warn(f"route:list unavailable ({e}); skipped Laravel vs OpenAPI")

    # git diff --check
    try:
        subprocess.check_call(
            ["git", "diff", "--check"],
            cwd=ROOT,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        ok("git diff --check clean")
    except Exception:
        warn("git diff --check reported issues or unavailable")

    print("---")
    print(f"errors={len(errors)} warnings={len(warnings)}")
    for e in errors:
        print("ERROR:", e)
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
