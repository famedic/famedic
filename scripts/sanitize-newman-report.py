#!/usr/bin/env python3
"""Sanitize Newman JSON export: strip secrets from bodies/headers."""
import json
import re
import sys
from pathlib import Path

SENSITIVE_KEYS = re.compile(
    r"(token|authorization|signature|secret|otp|phone|password|hmac|bearer)",
    re.I,
)


def redact_obj(obj):
    if isinstance(obj, dict):
        out = {}
        for k, v in obj.items():
            if SENSITIVE_KEYS.search(str(k)):
                out[k] = "[REDACTED]"
            else:
                out[k] = redact_obj(v)
        return out
    if isinstance(obj, list):
        return [redact_obj(x) for x in obj]
    if isinstance(obj, str) and len(obj) > 64 and re.fullmatch(r"[A-Za-z0-9._\-+/=]+", obj):
        return "[REDACTED]"
    return obj


def main() -> None:
    raw_path = Path(sys.argv[1])
    san_path = Path(sys.argv[2])
    summary_path = Path(sys.argv[3])
    raw = json.loads(raw_path.read_text(encoding="utf-8"))
    san = redact_obj(raw)
    san_path.write_text(json.dumps(san, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

    executions = []
    for run in san.get("run", {}).get("executions", []):
        name = run.get("item", {}).get("name", "")
        req = run.get("request", {}) or {}
        url = req.get("url", "")
        if isinstance(url, dict):
            url = url.get("raw") or url.get("host", "")
        status = None
        for a in run.get("assertions", []) or []:
            pass
        resp = run.get("response", {}) or {}
        status = resp.get("code")
        body = {}
        try:
            body = json.loads(resp.get("stream", "") or "{}")
        except Exception:
            body = {}
        err_code = None
        corr = None
        if isinstance(body, dict):
            err = body.get("error") or {}
            if isinstance(err, dict):
                err_code = err.get("code")
                corr = err.get("correlation_id")
        executions.append(
            {
                "case": name,
                "http": status,
                "error_code": err_code,
                "correlation_id": corr,
                "failed_assertions": sum(1 for a in (run.get("assertions") or []) if a.get("error")),
            }
        )

    stats = san.get("run", {}).get("stats", {})
    summary = {
        "executions": executions,
        "stats": stats,
        "failures": san.get("run", {}).get("failures", []),
    }
    summary_path.write_text(json.dumps(redact_obj(summary), indent=2) + "\n", encoding="utf-8")


if __name__ == "__main__":
    main()
