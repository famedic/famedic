#!/usr/bin/env python3
"""Prepare and sanitize the second Batch 2 functional window offline.

The script never performs HTTP, fixture, credential, or database operations.
It derives a Newman collection from an explicit scenario allowlist and emits
only a bounded result envelope.
"""
from __future__ import annotations

import argparse
import copy
import json
import re
import sys
from pathlib import Path
from urllib.parse import urlsplit


SCENARIO_RE = re.compile(r"^([BR]\d{2}[a-z]?\d*)\b", re.I)
AUTHORIZED = frozenset({
    "B01", "B01r", "B02", "B03", "B03r", "B04", "B05", "B06a", "B06b", "B06r",
    "B07", "B09", "B14", "B19", "B20", "B21", "B21r", "B21x", "B22", "B25",
    "R01", "R04", "R06", "R07",
})
EXPECTED_REQUEST_COUNTS = {sid: 1 for sid in AUTHORIZED}
EXPECTED_REQUEST_COUNTS["R01"] = 2
EXPECTED_REQUEST_COUNTS["R07"] = 3
FORBIDDEN_INCIDENTAL = frozenset({"R02b", "R03b"})
SECRET_KEYS = re.compile(
    r"(?:authorization|cookie|password|secret|hmac|otp|access[_-]?token|bearer|"
    r"consumer[_-]?credential|machine[_-]?credential|encrypted[_-]?secrets)", re.I
)
TOKEN = re.compile(r"(?:Bearer\s+|eyJ[A-Za-z0-9_-]+\.|\b[0-9]+\|[A-Za-z0-9]{16,})", re.I)
ERROR_CODE = re.compile(r"^[A-Z][A-Z0-9_]{2,63}$")


class WindowError(ValueError):
    pass


def scenario_id(name: str) -> str | None:
    match = SCENARIO_RE.match(name.strip())
    return match.group(1) if match else None


def load_json(path: Path) -> dict:
    if path.is_symlink() or not path.is_file() or path.stat().st_size > 8 * 1024 * 1024:
        raise WindowError("INPUT_UNREADABLE")
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError) as exc:
        raise WindowError("INPUT_INVALID") from exc
    if not isinstance(value, dict):
        raise WindowError("INPUT_OBJECT_REQUIRED")
    return value


def select_items(items: list, requested: set[str]) -> tuple[list, set[str]]:
    selected = []
    found = set()
    for item in items:
        if not isinstance(item, dict):
            raise WindowError("COLLECTION_ITEM_INVALID")
        children = item.get("item")
        if isinstance(children, list):
            nested, nested_found = select_items(children, requested)
            if nested:
                clone = copy.deepcopy(item)
                clone["item"] = nested
                selected.append(clone)
                found.update(nested_found)
            continue
        name = item.get("name")
        if not isinstance(name, str):
            raise WindowError("REQUEST_NAME_MISSING")
        sid = scenario_id(name)
        matched = sid if sid in requested else None
        if sid and matched is None:
            matched = next(
                (base for base in requested if base.startswith("R") and sid.startswith(base)), None
            )
        if matched:
            selected.append(copy.deepcopy(item))
            found.add(matched)
    return selected, found


def select_collection(source: Path, target: Path, raw_ids: str) -> dict:
    requested = {part.strip() for part in raw_ids.split(",") if part.strip()}
    if not requested or not requested <= AUTHORIZED:
        unknown = sorted(requested - AUTHORIZED)
        if "R02b" in unknown or "R03b" in unknown:
            raise WindowError("SCENARIO_NOT_AUTHORIZED:R02b/R03b")
        raise WindowError("SCENARIO_NOT_AUTHORIZED:" + ",".join(unknown or ["EMPTY"]))
    collection = load_json(source)
    items, found = select_items(collection.get("item", []), requested)
    if found != requested:
        raise WindowError("SCENARIO_NOT_FOUND:" + ",".join(sorted(requested - found)))
    collection["item"] = items
    info = collection.setdefault("info", {})
    info["name"] = str(info.get("name", "RC2")) + " — IT-0061 window 2 explicit selection"
    selected_count = sum(1 for _ in walk_requests(items))
    expected_count = sum(EXPECTED_REQUEST_COUNTS[sid] for sid in requested)
    if selected_count != expected_count:
        raise WindowError(f"REQUEST_COUNT_MISMATCH:{selected_count}!={expected_count}")
    info["_selection"] = {
        "scenario_ids": sorted(requested),
        "request_count": expected_count,
        "folder_selection_forbidden": True,
        "derived_collection": True,
    }
    if target.exists() or target.is_symlink():
        raise WindowError("OUTPUT_EXISTS")
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(json.dumps(collection, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")
    return {"scenario_ids": sorted(requested), "requests_selected": selected_count}


def walk_requests(items: list):
    for item in items:
        if isinstance(item.get("item"), list):
            yield from walk_requests(item["item"])
        else:
            yield item


def bounded_error_code(response: dict) -> str | None:
    body = response.get("body")
    if not isinstance(body, dict):
        return None
    error = body.get("error")
    code = error.get("code") if isinstance(error, dict) else None
    if code is None:
        return None
    if not isinstance(code, str) or not ERROR_CODE.fullmatch(code):
        raise WindowError("ERROR_CODE_INVALID")
    return code


def sanitize_report(source: Path, target: Path, allowed_ids: set[str], exit_code: int = 0) -> dict:
    report = load_json(source)
    executions = report.get("run", {}).get("executions")
    if not isinstance(executions, list) or not executions:
        raise WindowError("EXECUTIONS_MISSING")
    cases = []
    seen = set()
    expected_count = sum(EXPECTED_REQUEST_COUNTS.get(sid, 0) for sid in allowed_ids)
    for index, execution in enumerate(executions, 1):
        request = execution.get("request") if isinstance(execution, dict) else None
        response = execution.get("response") if isinstance(execution, dict) else None
        if not isinstance(request, dict) or not isinstance(response, dict):
            raise WindowError("EXECUTION_ENVELOPE_INVALID")
        name = execution.get("item", {}).get("name") or request.get("name")
        if not isinstance(name, str):
            raise WindowError("EXECUTION_NAME_MISSING")
        sid = scenario_id(name)
        normalized_sid = sid
        if sid.startswith("R") and len(sid) > 3:
            normalized_sid = sid[:3]
        if sid in FORBIDDEN_INCIDENTAL or normalized_sid not in allowed_ids:
            raise WindowError("EXECUTION_OUTSIDE_ALLOWLIST")
        request_key = name.strip()
        if request_key in seen:
            raise WindowError("EXECUTION_DUPLICATE")
        seen.add(request_key)
        method = request.get("method")
        url = request.get("url")
        if isinstance(url, dict):
            url = url.get("raw")
        if not isinstance(method, str) or not isinstance(url, str):
            raise WindowError("REQUEST_ENVELOPE_INVALID")
        status = response.get("code")
        if type(status) is not int or not 100 <= status <= 599:
            raise WindowError("HTTP_STATUS_INVALID")
        assertions = execution.get("assertions")
        if not isinstance(assertions, list) or not assertions:
            raise WindowError("ASSERTIONS_MISSING")
        projected = []
        for number, assertion in enumerate(assertions, 1):
            if not isinstance(assertion, dict):
                raise WindowError("ASSERTION_INVALID")
            projected.append({
                "assertion_id": f"{sid}-assertion-{number:03d}",
                "result": "SKIP" if assertion.get("skipped") else "FAIL" if assertion.get("error") else "PASS",
            })
        cases.append({
            "scenario_id": normalized_sid,
            "method": method.upper(),
            "path": urlsplit(url.replace("{{base_url}}", "")).path,
            "http_status": status,
            "error_code": bounded_error_code(response),
            "assertions": projected,
            "duration_ms": response.get("responseTime") if isinstance(response.get("responseTime"), (int, float)) else None,
        })
    if len(cases) != expected_count:
        raise WindowError(f"EXECUTION_COUNT_MISMATCH:{len(cases)}!={expected_count}")
    output = {"schema": "it0061-functional-window-2.v1", "exit_code": exit_code, "cases": cases}
    serialized = json.dumps(output, ensure_ascii=False, indent=2) + "\n"
    if SECRET_KEYS.search(serialized) or TOKEN.search(serialized):
        raise WindowError("SANITIZED_OUTPUT_SECRET_PATTERN")
    if target.exists() or target.is_symlink():
        raise WindowError("OUTPUT_EXISTS")
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(serialized, encoding="utf-8", newline="\n")
    return {"cases": len(cases), "assertions": sum(len(c["assertions"]) for c in cases)}


def sanitize_log(source: Path, target: Path, exit_code: int) -> dict:
    if source.is_symlink() or not source.is_file() or source.stat().st_size > 2 * 1024 * 1024:
        raise WindowError("LOG_INPUT_UNREADABLE")
    raw = source.read_text(encoding="utf-8", errors="replace")
    categories = []
    if re.search(r"assert|expect|test", raw, re.I):
        categories.append("ASSERTION_FAILURE")
    if re.search(r"error|exception|fatal", raw, re.I):
        categories.append("RUNNER_ERROR")
    if SECRET_KEYS.search(raw) or TOKEN.search(raw):
        categories.append("REDACTION_APPLIED")
    output = {
        "schema": "it0061-functional-window-2.log.v1",
        "exit_code": exit_code,
        "line_count": len(raw.splitlines()),
        "categories": sorted(set(categories)),
    }
    serialized = json.dumps(output, ensure_ascii=False, indent=2) + "\n"
    if SECRET_KEYS.search(serialized) or TOKEN.search(serialized):
        raise WindowError("SANITIZED_OUTPUT_SECRET_PATTERN")
    if target.exists() or target.is_symlink():
        raise WindowError("OUTPUT_EXISTS")
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(serialized, encoding="utf-8", newline="\n")
    return {"exit_code": exit_code, "line_count": output["line_count"]}


def main() -> int:
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="command", required=True)
    select = sub.add_parser("select")
    select.add_argument("collection", type=Path)
    select.add_argument("output", type=Path)
    select.add_argument("--ids", required=True)
    sanitize = sub.add_parser("sanitize")
    sanitize.add_argument("raw_report", type=Path)
    sanitize.add_argument("output", type=Path)
    sanitize.add_argument("--ids", required=True)
    sanitize.add_argument("--exit-code", type=int, default=0)
    log = sub.add_parser("sanitize-log")
    log.add_argument("raw_log", type=Path)
    log.add_argument("output", type=Path)
    log.add_argument("--exit-code", required=True, type=int)
    args = parser.parse_args()
    try:
        if args.command == "select":
            result = select_collection(args.collection, args.output, args.ids)
        elif args.command == "sanitize":
            ids = {part.strip() for part in args.ids.split(",") if part.strip()}
            if not ids <= AUTHORIZED:
                raise WindowError("SCENARIO_NOT_AUTHORIZED")
            result = sanitize_report(args.raw_report, args.output, ids, args.exit_code)
        else:
            result = sanitize_log(args.raw_log, args.output, args.exit_code)
        print(json.dumps({"status": "PASS", **result}, sort_keys=True))
        return 0
    except (OSError, WindowError):
        print("BLOCKED: functional-window-2 preparation rejected; values omitted", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
