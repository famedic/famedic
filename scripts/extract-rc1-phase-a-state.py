#!/usr/bin/env python3
"""Extrae conversation_id, channel_event_id y challenge_id del reporte Newman (Fase A)."""
from __future__ import annotations

import json
import sys
from pathlib import Path
from typing import Any


def newman_response_json(response: Any) -> dict[str, Any] | None:
    """Decodifica el cuerpo JSON de una respuesta Newman (stream string, Buffer o body)."""
    if not isinstance(response, dict):
        return None

    body = response.get("body")
    if isinstance(body, dict):
        return body
    if isinstance(body, str) and body.strip():
        try:
            parsed = json.loads(body)
        except json.JSONDecodeError:
            parsed = None
        if isinstance(parsed, dict):
            return parsed

    stream = response.get("stream")
    if isinstance(stream, str) and stream.strip():
        try:
            parsed = json.loads(stream)
        except json.JSONDecodeError:
            return None
        return parsed if isinstance(parsed, dict) else None

    if isinstance(stream, dict):
        data = stream.get("data")
        if stream.get("type") == "Buffer" and isinstance(data, list):
            try:
                text = bytes(int(b) & 0xFF for b in data).decode("utf-8")
                parsed = json.loads(text)
            except (UnicodeDecodeError, json.JSONDecodeError, TypeError, ValueError):
                return None
            return parsed if isinstance(parsed, dict) else None
        if isinstance(data, dict):
            return data
        if isinstance(data, str) and data.strip():
            try:
                parsed = json.loads(data)
            except json.JSONDecodeError:
                return None
            return parsed if isinstance(parsed, dict) else None

    return None


def _header_value(headers: Any, name: str) -> str | None:
    if not isinstance(headers, list):
        return None
    target = name.lower()
    for entry in headers:
        if not isinstance(entry, dict):
            continue
        key = str(entry.get("key", "")).lower()
        if key == target:
            value = entry.get("value")
            if isinstance(value, str) and value.strip():
                return value.strip()
    return None


def is_request_code_item(name: str) -> bool:
    lowered = name.lower()
    return "request-code" in lowered or "authloginrequestcode" in lowered


def extract_correlation_id(run: dict[str, Any]) -> str | None:
    response = run.get("response") or {}
    header_corr = _header_value(response.get("header"), "X-Correlation-ID")
    if header_corr:
        return header_corr

    body = newman_response_json(response)
    if isinstance(body, dict):
        error = body.get("error")
        if isinstance(error, dict):
            cid = error.get("correlation_id")
            if isinstance(cid, str) and cid.strip():
                return cid.strip()

    request = run.get("request") or {}
    req_corr = _header_value(request.get("header"), "X-Correlation-ID")
    if req_corr:
        return req_corr

    return None


def extract_phase_a_state(raw_report: dict[str, Any]) -> dict[str, str]:
    executions = raw_report.get("run", {}).get("executions", []) or []
    state: dict[str, str] = {}

    for run in executions:
        if not isinstance(run, dict):
            continue
        item = run.get("item") or {}
        name = str(item.get("name", ""))
        request = run.get("request") or {}
        body_raw = (request.get("body") or {}).get("raw") or ""
        if isinstance(body_raw, str) and "conversation_id" in body_raw and "challenge_id" not in body_raw:
            try:
                payload = json.loads(body_raw)
            except json.JSONDecodeError:
                payload = {}
            if isinstance(payload, dict):
                conv = payload.get("conversation_id")
                event = payload.get("channel_event_id")
                if isinstance(conv, str) and conv.strip():
                    state["conversation_id"] = conv.strip()
                if isinstance(event, str) and event.strip():
                    state["channel_event_id"] = event.strip()

        if is_request_code_item(name):
            response = run.get("response") or {}
            status = response.get("code")
            body = newman_response_json(response)
            if status == 202 and isinstance(body, dict):
                data = body.get("data")
                if isinstance(data, dict):
                    challenge_id = data.get("challenge_id")
                    if isinstance(challenge_id, str) and challenge_id.strip():
                        state["challenge_id"] = challenge_id.strip()

            correlation_id = extract_correlation_id(run)
            if correlation_id:
                state["correlation_id"] = correlation_id

    return state


def main() -> int:
    if len(sys.argv) < 2:
        print("Uso: extract-rc1-phase-a-state.py <newman-raw.json> [salida.json]", file=sys.stderr)
        return 2

    raw_path = Path(sys.argv[1])
    out_path = Path(sys.argv[2]) if len(sys.argv) > 2 else None

    raw = json.loads(raw_path.read_text(encoding="utf-8"))
    state = extract_phase_a_state(raw)

    missing = [k for k in ("conversation_id", "channel_event_id", "challenge_id") if k not in state]
    if missing:
        print("BLOCKED: Fase A incompleta, faltan: " + ", ".join(missing), file=sys.stderr)
        return 1

    payload: dict[str, str] = {
        "conversation_id": state["conversation_id"],
        "channel_event_id": state["channel_event_id"],
        "challenge_id": state["challenge_id"],
        "note": "Usar con Fase B y OTP_CODE fresco del monitor staging. No commitear.",
    }
    if state.get("correlation_id"):
        payload["correlation_id"] = state["correlation_id"]

    encoded = json.dumps(payload, indent=2, ensure_ascii=False) + "\n"
    if out_path:
        out_path.write_text(encoded, encoding="utf-8")
    else:
        sys.stdout.write(encoded)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
