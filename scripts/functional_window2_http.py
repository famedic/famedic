#!/usr/bin/env python3
"""Execute an explicit functional-window plan against a controlled HTTP target.

The runner is deliberately independent of Newman: it accepts a derived plan,
never follows redirects, validates every destination, projects only sanitized
results, and runs cleanup in a finally block after any HTTP error, timeout or
interrupt. It is suitable for local validation; QA use remains blocked until
its secure credential/cleanup adapter is supplied.
"""
from __future__ import annotations

import argparse
import json
import signal
import sys
import time
from dataclasses import dataclass
from http.client import HTTPException
from pathlib import Path
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.parse import urljoin, urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener


class RunnerError(ValueError):
    pass


class NoRedirects(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise RunnerError("REDIRECT_FORBIDDEN")


@dataclass(frozen=True)
class Case:
    case_id: str
    method: str
    path: str
    expected: tuple[int, ...]
    body: dict[str, Any] | None = None


def _origin(url: str) -> tuple[str, str, int | None]:
    p = urlsplit(url)
    if p.scheme not in {"http", "https"} or not p.hostname:
        raise RunnerError("URL_INVALID")
    return p.scheme, p.hostname.lower(), p.port


def _same_origin(base: str, target: str) -> bool:
    bs, bh, bp = _origin(base)
    ts, th, tp = _origin(target)
    return (bs, bh, bp or (443 if bs == "https" else 80)) == (ts, th, tp or (443 if ts == "https" else 80))


def load_plan(path: Path) -> tuple[list[Case], list[Case]]:
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict) or not isinstance(data.get("requests"), list):
        raise RunnerError("PLAN_INVALID")

    def parse(items: list[Any], label: str) -> list[Case]:
        result: list[Case] = []
        seen: set[str] = set()
        for raw in items:
            if not isinstance(raw, dict):
                raise RunnerError(f"{label}_ITEM_INVALID")
            case_id = raw.get("id")
            method = raw.get("method")
            path_value = raw.get("path")
            expected = raw.get("expected_status")
            if not isinstance(case_id, str) or not case_id or case_id in seen:
                raise RunnerError(f"{label}_DUPLICATE_OR_INVALID_ID")
            if not isinstance(method, str) or method.upper() not in {"GET", "POST", "PUT", "PATCH", "DELETE"}:
                raise RunnerError("METHOD_INVALID")
            if not isinstance(path_value, str) or not path_value.startswith("/") or "//" in path_value:
                raise RunnerError("PATH_INVALID")
            if isinstance(expected, int):
                expected = [expected]
            if not isinstance(expected, list) or not expected or any(type(x) is not int or x < 100 or x > 599 for x in expected):
                raise RunnerError("EXPECTED_STATUS_INVALID")
            body = raw.get("json")
            if body is not None and not isinstance(body, dict):
                raise RunnerError("JSON_BODY_INVALID")
            result.append(Case(case_id, method.upper(), path_value, tuple(expected), body))
            seen.add(case_id)
        return result

    requests = parse(data["requests"], "REQUEST")
    cleanup = parse(data.get("cleanup", []), "CLEANUP")
    if not requests:
        raise RunnerError("REQUESTS_EMPTY")
    return requests, cleanup


def _project_error(exc: BaseException) -> str:
    if isinstance(exc, HTTPError):
        return f"HTTP_{exc.code}"
    if isinstance(exc, TimeoutError):
        return "TIMEOUT"
    if isinstance(exc, (URLError, HTTPException, OSError)):
        return "TRANSPORT_ERROR"
    return "RUNNER_ERROR"


def _request(base_url: str, case: Case, timeout: float) -> tuple[int, int]:
    target = urljoin(base_url.rstrip("/") + "/", case.path.lstrip("/"))
    if not _same_origin(base_url, target):
        raise RunnerError("DESTINATION_NOT_ALLOWED")
    payload = None
    headers = {"Accept": "application/json"}
    if case.body is not None:
        payload = json.dumps(case.body, separators=(",", ":")).encode("utf-8")
        headers["Content-Type"] = "application/json"
    request = Request(target, data=payload, method=case.method, headers=headers)
    started = time.monotonic()
    try:
        with build_opener(NoRedirects()).open(request, timeout=timeout) as response:
            status = response.status
            response.read(1024 * 1024)
    except HTTPError as exc:
        status = exc.code
    return status, round((time.monotonic() - started) * 1000)


def run(plan: Path, base_url: str, output: Path, timeout: float = 5.0) -> int:
    requests, cleanup = load_plan(plan)
    # The base is the only authority. The caller must opt into a non-local host.
    scheme, host, _ = _origin(base_url)
    if host not in {"127.0.0.1", "localhost", "::1"}:
        raise RunnerError("NON_LOCAL_TARGET_REQUIRES_EXPLICIT_ADAPTER")
    if scheme != "http":
        raise RunnerError("LOCAL_TARGET_MUST_USE_HTTP")
    results: list[dict[str, Any]] = []
    cleanup_results: list[dict[str, Any]] = []
    exit_code = 0
    interrupted = False

    def stop(_signum, _frame):
        nonlocal interrupted
        interrupted = True
        raise KeyboardInterrupt

    old = signal.signal(signal.SIGINT, stop)
    try:
        for case in requests:
            try:
                status, duration = _request(base_url, case, timeout)
                ok = status in case.expected
                results.append({"id": case.case_id, "method": case.method, "path": case.path, "http_status": status, "ok": ok, "duration_ms": duration})
                if not ok:
                    exit_code = 1
            except BaseException as exc:
                results.append({"id": case.case_id, "method": case.method, "path": case.path, "ok": False, "error": _project_error(exc)})
                exit_code = 1
                break
    except KeyboardInterrupt:
        interrupted = True
        exit_code = 130
    finally:
        for case in cleanup:
            try:
                status, duration = _request(base_url, case, timeout)
                ok = status in case.expected
                cleanup_results.append({"id": case.case_id, "http_status": status, "ok": ok, "duration_ms": duration})
                if not ok and exit_code == 0:
                    exit_code = 1
            except BaseException as exc:
                cleanup_results.append({"id": case.case_id, "ok": False, "error": _project_error(exc)})
                exit_code = 1
        signal.signal(signal.SIGINT, old)

    projected = {"schema": "it0061-functional-window-2-http.v1", "exit_code": exit_code, "interrupted": interrupted, "requests": results, "cleanup": cleanup_results}
    output.write_text(json.dumps(projected, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    return exit_code


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("plan", type=Path)
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--timeout", type=float, default=5.0)
    args = parser.parse_args(argv)
    try:
        return run(args.plan, args.base_url, args.output, args.timeout)
    except (OSError, RunnerError, json.JSONDecodeError) as exc:
        print(f"BLOCKED: {type(exc).__name__}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
