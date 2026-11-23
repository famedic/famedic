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
import re
import signal
import shlex
import subprocess
import ssl
import sys
import time
from dataclasses import dataclass
from http.client import HTTPException
from pathlib import Path
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.parse import urljoin, urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener


QA_HOSTS = frozenset({"staging.famedic.com.mx", "famedic-otp.on-forge.com"})
SHA_RE = re.compile(r"^[0-9a-f]{40}$", re.I)


class RunnerError(ValueError):
    pass


class CredentialProviderError(RunnerError):
    pass


class CredentialProvider:
    """Read short-lived credentials from a custody command without exposing them."""

    def __init__(self, command: str | None = None, values: dict[str, str] | None = None):
        self._command = command
        self._values = values
        if command is None and values is None:
            raise CredentialProviderError("CREDENTIAL_PROVIDER_REQUIRED")

    def load(self, aliases: set[str]) -> dict[str, str]:
        if self._values is not None:
            result = dict(self._values)
        else:
            try:
                argv = shlex.split(self._command or "", posix=sys.platform != "win32")
                if not argv:
                    raise CredentialProviderError("CREDENTIAL_PROVIDER_INVALID")
                completed = subprocess.run(
                    argv,
                    input=json.dumps({"aliases": sorted(aliases)}),
                    text=True,
                    capture_output=True,
                    timeout=10,
                    check=False,
                )
            except (OSError, subprocess.SubprocessError, ValueError):
                raise CredentialProviderError("CREDENTIAL_PROVIDER_FAILED") from None
            if completed.returncode != 0:
                raise CredentialProviderError("CREDENTIAL_PROVIDER_FAILED")
            try:
                result = json.loads(completed.stdout)
            except json.JSONDecodeError:
                raise CredentialProviderError("CREDENTIAL_PROVIDER_INVALID") from None
            if not isinstance(result, dict):
                raise CredentialProviderError("CREDENTIAL_PROVIDER_INVALID")

        if set(result) != aliases or any(not isinstance(v, str) or not v for v in result.values()):
            raise CredentialProviderError("CREDENTIAL_PROVIDER_ALIASES_MISMATCH")
        return result


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
    writes: bool = False
    bearer_alias: str | None = None
    machine_alias: str | None = None
    assertions: dict[str, Any] | None = None


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
            writes = raw.get("writes", False)
            if type(writes) is not bool:
                raise RunnerError("WRITES_FLAG_INVALID")
            auth = raw.get("auth", {})
            if auth is None:
                auth = {}
            if not isinstance(auth, dict):
                raise RunnerError("AUTH_INVALID")
            bearer_alias = auth.get("bearer_alias")
            machine_alias = auth.get("machine_alias")
            for alias in (bearer_alias, machine_alias):
                if alias is not None and (not isinstance(alias, str) or not re.fullmatch(r"[a-z0-9._-]+", alias)):
                    raise RunnerError("AUTH_ALIAS_INVALID")
            assertions = raw.get("assertions")
            if assertions is not None and not isinstance(assertions, dict):
                raise RunnerError("ASSERTIONS_INVALID")
            result.append(Case(case_id, method.upper(), path_value, tuple(expected), body, writes, bearer_alias, machine_alias, assertions))
            seen.add(case_id)
        return result

    requests = parse(data["requests"], "REQUEST")
    cleanup = parse(data.get("cleanup", []), "CLEANUP")
    if not requests:
        raise RunnerError("REQUESTS_EMPTY")
    allowed = data.get("selection", {})
    if isinstance(allowed, dict) and "authorized_ids" in allowed:
        authorized = allowed.get("authorized_ids")
        if not isinstance(authorized, list) or any(not isinstance(x, str) for x in authorized):
            raise RunnerError("SELECTION_INVALID")
        request_ids = [case.case_id for case in requests]
        if len(request_ids) != len(set(request_ids)) or any(x not in authorized for x in request_ids):
            raise RunnerError("SELECTION_NOT_AUTHORIZED")
        expected_count = allowed.get("expected_request_count")
        if expected_count is not None and expected_count != len(requests):
            raise RunnerError("SELECTION_COUNT_MISMATCH")
    return requests, cleanup


def validate_mode(mode: str, base_url: str, metadata: dict[str, Any]) -> ssl.SSLContext | None:
    """Validate mode/target/guards without opening a connection."""
    scheme, host, port = _origin(base_url)
    if mode == "local":
        if host not in {"127.0.0.1", "localhost", "::1"} or scheme != "http":
            raise RunnerError("LOCAL_TARGET_MUST_BE_LOOPBACK_HTTP")
        return None
    if mode != "qa":
        raise RunnerError("MODE_INVALID")
    if scheme != "https" or host not in QA_HOSTS or port is not None:
        raise RunnerError("QA_TARGET_NOT_ALLOWLISTED_HTTPS")
    target_env = metadata.get("target_env")
    expected_sha = metadata.get("expected_release_sha")
    if target_env != "staging" or not isinstance(expected_sha, str) or not SHA_RE.fullmatch(expected_sha):
        raise RunnerError("QA_RELEASE_GUARD_INVALID")
    for key in ("qa_writes_authorized", "leo_negative_writes_authorized"):
        value = metadata.get(key, "0")
        if type(value) is not str or value not in {"0", "1"}:
            raise RunnerError("QA_FLAG_NOT_EXACT")
    if metadata.get("https_verified") is not True:
        raise RunnerError("TLS_VERIFICATION_REQUIRED")
    # No unverified context is ever constructed. The actual connection path
    # uses this context after the local validation has passed.
    return ssl.create_default_context()


def _project_error(exc: BaseException) -> str:
    if isinstance(exc, HTTPError):
        return f"HTTP_{exc.code}"
    if isinstance(exc, TimeoutError):
        return "TIMEOUT"
    if isinstance(exc, (URLError, HTTPException, OSError)):
        return "TRANSPORT_ERROR"
    return "RUNNER_ERROR"


def _path_value(value: Any, path: str) -> Any:
    current = value
    for segment in path.split("."):
        if not isinstance(current, dict) or segment not in current:
            return None
        current = current[segment]
    return current


def _assert_response(body: Any, assertions: dict[str, Any] | None) -> str | None:
    if not assertions:
        return None
    if not isinstance(body, (dict, list)):
        return "RESPONSE_JSON_REQUIRED"
    for path in assertions.get("required_paths", []):
        if _path_value(body, path) is None:
            return "ASSERTION_REQUIRED_PATH"
    for path in assertions.get("forbidden_paths", []):
        if _path_value(body, path) is not None:
            return "ASSERTION_FORBIDDEN_PATH"
    for path, expected in assertions.get("equals", {}).items():
        if _path_value(body, path) != expected:
            return "ASSERTION_VALUE_MISMATCH"
    return None


def _request(base_url: str, case: Case, timeout: float, tls_context: ssl.SSLContext | None = None, credentials: dict[str, str] | None = None) -> tuple[int, int, Any, str | None]:
    target = urljoin(base_url.rstrip("/") + "/", case.path.lstrip("/"))
    if not _same_origin(base_url, target):
        raise RunnerError("DESTINATION_NOT_ALLOWED")
    payload = None
    headers = {"Accept": "application/json"}
    if case.bearer_alias:
        if credentials is None or case.bearer_alias not in credentials:
            raise CredentialProviderError("CREDENTIAL_ALIAS_UNAVAILABLE")
        headers["Authorization"] = "Bearer " + credentials[case.bearer_alias]
    if case.machine_alias:
        if credentials is None or case.machine_alias not in credentials:
            raise CredentialProviderError("CREDENTIAL_ALIAS_UNAVAILABLE")
        headers["X-Api-Consumer-Credential"] = credentials[case.machine_alias]
    if case.body is not None:
        payload = json.dumps(case.body, separators=(",", ":")).encode("utf-8")
        headers["Content-Type"] = "application/json"
    request = Request(target, data=payload, method=case.method, headers=headers)
    started = time.monotonic()
    try:
        handlers: list[Any] = [NoRedirects()]
        if tls_context is not None:
            from urllib.request import HTTPSHandler
            handlers.append(HTTPSHandler(context=tls_context))
        with build_opener(*handlers).open(request, timeout=timeout) as response:
            status = response.status
            raw = response.read(1024 * 1024)
            try:
                body = json.loads(raw.decode("utf-8")) if raw else None
            except (UnicodeDecodeError, json.JSONDecodeError):
                body = None
    except HTTPError as exc:
        status = exc.code
        try:
            raw = exc.read(1024 * 1024)
            body = json.loads(raw.decode("utf-8")) if raw else None
        except (OSError, UnicodeDecodeError, json.JSONDecodeError):
            body = None
    assertion_error = _assert_response(body, case.assertions)
    return status, round((time.monotonic() - started) * 1000), body, assertion_error


def run(plan: Path, base_url: str, output: Path, timeout: float = 5.0, mode: str = "local", credential_command: str | None = None, credential_values: dict[str, str] | None = None) -> int:
    data = json.loads(plan.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise RunnerError("PLAN_INVALID")
    requests, cleanup = load_plan(plan)
    metadata = data.get("qa_guards", {}) if isinstance(data.get("qa_guards", {}), dict) else {}
    tls_context = validate_mode(mode, base_url, metadata)
    if mode == "qa" and any(case.writes for case in requests):
        if metadata.get("qa_writes_authorized") != "1":
            raise RunnerError("QA_WRITES_NOT_AUTHORIZED")
    if any(case.case_id == "B25" and case.writes for case in requests):
        if metadata.get("leo_negative_writes_authorized") != "1":
            raise RunnerError("LEO_NEGATIVE_WRITES_NOT_AUTHORIZED")
    if mode == "qa" and any(case.writes for case in requests) and not cleanup:
        raise RunnerError("CLEANUP_PLAN_REQUIRED")
    aliases = {alias for case in requests + cleanup for alias in (case.bearer_alias, case.machine_alias) if alias}
    credentials = CredentialProvider(credential_command, credential_values).load(aliases) if aliases else None
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
                status, duration, _body, assertion_error = _request(base_url, case, timeout, tls_context, credentials)
                ok = status in case.expected and assertion_error is None
                result = {"id": case.case_id, "method": case.method, "path": case.path, "http_status": status, "ok": ok, "duration_ms": duration}
                if assertion_error:
                    result["assertion_error"] = assertion_error
                results.append(result)
                if not ok:
                    exit_code = 1
            except BaseException as exc:
                if isinstance(exc, KeyboardInterrupt):
                    raise
                results.append({"id": case.case_id, "method": case.method, "path": case.path, "ok": False, "error": _project_error(exc)})
                exit_code = 1
                break
    except KeyboardInterrupt:
        interrupted = True
        exit_code = 130
    finally:
        for case in cleanup:
            try:
                status, duration, _body, assertion_error = _request(base_url, case, timeout, tls_context, credentials)
                ok = status in case.expected and assertion_error is None
                cleanup_results.append({"id": case.case_id, "http_status": status, "ok": ok, "duration_ms": duration})
                if not ok and exit_code == 0:
                    exit_code = 1
            except BaseException as exc:
                cleanup_results.append({"id": case.case_id, "ok": False, "error": _project_error(exc)})
                exit_code = 1
        signal.signal(signal.SIGINT, old)

    cleanup_status = "CLEANUP_FAILED" if any(not item.get("ok", False) for item in cleanup_results) else "PASS"
    if cleanup_status == "CLEANUP_FAILED":
        exit_code = 1
    projected = {"schema": "it0061-functional-window-2-http.v1", "exit_code": exit_code, "interrupted": interrupted, "cleanup_status": cleanup_status, "requests": results, "cleanup": cleanup_results}
    output.write_text(json.dumps(projected, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    return exit_code


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("plan", type=Path)
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--timeout", type=float, default=5.0)
    parser.add_argument("--mode", choices=("local", "qa"), default="local")
    parser.add_argument("--credential-command", help="Custody helper; receives aliases on stdin and returns secret values on stdout")
    args = parser.parse_args(argv)
    try:
        return run(args.plan, args.base_url, args.output, args.timeout, args.mode, args.credential_command)
    except (OSError, RunnerError, json.JSONDecodeError) as exc:
        print(f"BLOCKED: {type(exc).__name__}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
