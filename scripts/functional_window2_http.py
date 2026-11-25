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
import shutil
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


class InventoryProviderError(RunnerError):
    pass


class CredentialProvider:
    """Read short-lived credentials from a custody command without exposing them."""

    def __init__(self, command: str | None = None, values: dict[str, str] | None = None, scenario: str = "functional-window-2"):
        self._command = command
        self._values = values
        self._scenario = scenario
        if scenario not in {"functional-window-2", "functional-window-2-memberships", "functional-window-2-continuity"}:
            raise CredentialProviderError("CREDENTIAL_SCENARIO_NOT_ALLOWED")
        if command is None and values is None:
            raise CredentialProviderError("CREDENTIAL_PROVIDER_REQUIRED")
        if command is not None:
            argv = shlex.split(command, posix=sys.platform != "win32")
            if not argv or (not Path(argv[0]).exists() and shutil.which(argv[0]) is None):
                raise CredentialProviderError("CREDENTIAL_PROVIDER_UNAVAILABLE")

    def load(self, aliases: set[str]) -> dict[str, str]:
        if self._values is not None:
            result = dict(self._values)
        else:
            result = self._invoke({"action": "read", "scenario": self._scenario, "aliases": sorted(aliases)})
        if not isinstance(result, dict):
            raise CredentialProviderError("CREDENTIAL_PROVIDER_INVALID")
        if set(result) != aliases or any(not isinstance(v, str) or not v for v in result.values()):
            raise CredentialProviderError("CREDENTIAL_PROVIDER_ALIASES_MISMATCH")
        return result

    def revoke(self, aliases: set[str]) -> None:
        if self._values is not None:
            return
        result = self._invoke({"action": "revoke", "scenario": self._scenario, "aliases": sorted(aliases)})
        if not isinstance(result, dict) or sorted(result.get("revoked", [])) != sorted(aliases):
            raise CredentialProviderError("CREDENTIAL_REVOCATION_UNCONFIRMED")

    def _invoke(self, request: dict[str, Any]) -> dict[str, Any]:
        try:
            argv = shlex.split(self._command or "", posix=sys.platform != "win32")
            if not argv:
                raise CredentialProviderError("CREDENTIAL_PROVIDER_INVALID")
            completed = subprocess.run(
                argv,
                input=json.dumps(request),
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
        return result


class InventoryProvider:
    """Verify ownership immediately before a DELETE, without exposing secrets."""

    def __init__(self, command: str | None = None):
        self._command = command
        if not command:
            raise InventoryProviderError("INVENTORY_PROVIDER_REQUIRED")
        argv = shlex.split(command, posix=sys.platform != "win32")
        if not argv or (not Path(argv[0]).exists() and shutil.which(argv[0]) is None):
            raise InventoryProviderError("INVENTORY_PROVIDER_UNAVAILABLE")

    def verify(self, namespace: str, resource_id: int, record: dict[str, Any], expected_sha: str) -> None:
        result = self._invoke({"action": "verify", "namespace": namespace, "resource_id": resource_id, **record, "expected_sha": expected_sha})
        if result != {"status": "verified", "namespace": namespace, "resource_id": resource_id, "purpose": record.get("purpose"), "owner": record.get("owner")}:
            raise InventoryProviderError("INVENTORY_OWNERSHIP_UNCONFIRMED")

    def register(self, namespace: str, resource_id: int, record: dict[str, Any], source_case: str, expected_sha: str) -> None:
        result = self._invoke({"action": "register", "namespace": namespace, "resource_id": resource_id, **record, "source_case": source_case, "expected_sha": expected_sha})
        if result != {"status": "registered", "namespace": namespace, "resource_id": resource_id, "purpose": record.get("purpose"), "owner": record.get("owner")}:
            raise InventoryProviderError("INVENTORY_REGISTRATION_UNCONFIRMED")

    def unregister(self, namespace: str, resource_id: int, record: dict[str, Any], expected_sha: str) -> None:
        result = self._invoke({"action": "unregister", "namespace": namespace, "resource_id": resource_id, **record, "expected_sha": expected_sha})
        if result != {"status": "unregistered", "namespace": namespace, "resource_id": resource_id}:
            raise InventoryProviderError("INVENTORY_UNREGISTRATION_UNCONFIRMED")

    def _invoke(self, request: dict[str, Any]) -> dict[str, Any]:
        try:
            completed = subprocess.run(
                shlex.split(self._command or "", posix=sys.platform != "win32"),
                input=json.dumps(request), text=True, capture_output=True, timeout=10, check=False,
            )
        except (OSError, subprocess.SubprocessError, ValueError):
            raise InventoryProviderError("INVENTORY_PROVIDER_FAILED") from None
        if completed.returncode != 0:
            raise InventoryProviderError("INVENTORY_PROVIDER_FAILED")
        try:
            result = json.loads(completed.stdout)
        except json.JSONDecodeError:
            raise InventoryProviderError("INVENTORY_PROVIDER_INVALID") from None
        if not isinstance(result, dict):
            raise InventoryProviderError("INVENTORY_PROVIDER_INVALID")
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
    inventory_ref: str | None = None
    source_case: str | None = None
    register_after_success: bool = False
    unregister_after_success: bool = False
    headers: dict[str, str] | None = None
    register_id_path: str | None = None
    functional_case: bool = False


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
            # Do not silently discard a request body under the non-consumed
            # `body` key.  A plan with that typo previously sent an empty POST
            # and turned a business assertion into an authorization 403.
            if "body" in raw and "json" not in raw:
                raise RunnerError("JSON_BODY_KEY_INVALID")
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
            inventory_ref = raw.get("inventory_ref")
            if inventory_ref is not None and (not isinstance(inventory_ref, str) or not inventory_ref):
                raise RunnerError("INVENTORY_REF_INVALID")
            source_case = raw.get("source_case")
            if source_case is not None and (not isinstance(source_case, str) or not source_case):
                raise RunnerError("SOURCE_CASE_INVALID")
            register_after_success = raw.get("register_after_success", False)
            if type(register_after_success) is not bool:
                raise RunnerError("INVENTORY_REGISTER_FLAG_INVALID")
            unregister_after_success = raw.get("unregister_after_success", False)
            if type(unregister_after_success) is not bool:
                raise RunnerError("INVENTORY_UNREGISTER_FLAG_INVALID")
            headers = raw.get("headers", {})
            if not isinstance(headers, dict) or any(not isinstance(k, str) or not isinstance(v, str) for k, v in headers.items()):
                raise RunnerError("REQUEST_HEADERS_INVALID")
            if any(k.lower() in {"authorization", "x-consumer-credential", "cookie"} for k in headers):
                raise RunnerError("CREDENTIAL_HEADER_IN_PLAN")
            if any("\r" in v or "\n" in v for v in headers.values()):
                raise RunnerError("REQUEST_HEADER_VALUE_INVALID")
            register_id_path = raw.get("register_id_path")
            if register_id_path is not None and (not isinstance(register_id_path, str) or not re.fullmatch(r"[A-Za-z0-9_.]+", register_id_path)):
                raise RunnerError("REGISTER_ID_PATH_INVALID")
            functional_case = raw.get("functional_case", False)
            if type(functional_case) is not bool:
                raise RunnerError("FUNCTIONAL_CASE_FLAG_INVALID")
            result.append(Case(case_id, method.upper(), path_value, tuple(expected), body, writes, bearer_alias, machine_alias, assertions, inventory_ref, source_case, register_after_success, unregister_after_success, headers, register_id_path, functional_case))
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
    _validate_comparisons(data.get("comparisons", []), {case.case_id for case in requests})
    return requests, cleanup


def _validate_comparisons(raw: Any, request_ids: set[str]) -> None:
    """Validate ID-only projections without permitting response-body output."""
    if raw is None:
        return
    if not isinstance(raw, list):
        raise RunnerError("COMPARISONS_INVALID")
    seen: set[str] = set()
    for item in raw:
        if not isinstance(item, dict):
            raise RunnerError("COMPARISON_ITEM_INVALID")
        comparison_id = item.get("id")
        left_case = item.get("left_case")
        right_case = item.get("right_case")
        left_path = item.get("left_path")
        right_path = item.get("right_path")
        if (not isinstance(comparison_id, str) or not re.fullmatch(r"[A-Za-z0-9_.-]+", comparison_id)
                or comparison_id in seen):
            raise RunnerError("COMPARISON_ID_INVALID")
        if (not isinstance(left_case, str) or left_case not in request_ids
                or not isinstance(right_case, str) or right_case not in request_ids):
            raise RunnerError("COMPARISON_CASE_INVALID")
        if (not isinstance(left_path, str) or not re.fullmatch(r"[A-Za-z0-9_.]+", left_path)
                or not isinstance(right_path, str) or not re.fullmatch(r"[A-Za-z0-9_.]+", right_path)):
            raise RunnerError("COMPARISON_PATH_INVALID")
        seen.add(comparison_id)


def _project_comparisons(raw: Any, response_bodies: dict[str, Any], successful_cases: set[str]) -> list[dict[str, Any]]:
    """Project only synthetic scalar IDs and a boolean comparison outcome."""
    if not raw:
        return []
    projected: list[dict[str, Any]] = []
    for item in raw:
        left_id = _path_value(response_bodies.get(item["left_case"]), item["left_path"])
        right_id = _path_value(response_bodies.get(item["right_case"]), item["right_path"])
        comparable = item["left_case"] in successful_cases and item["right_case"] in successful_cases \
            and isinstance(left_id, int) and not isinstance(left_id, bool) and left_id > 0 \
            and isinstance(right_id, int) and not isinstance(right_id, bool) and right_id > 0
        projected.append({
            "id": item["id"],
            "left_case": item["left_case"],
            "right_case": item["right_case"],
            "left_id": left_id if comparable else None,
            "right_id": right_id if comparable else None,
            "equal": bool(comparable and left_id == right_id),
            "status": "COMPARABLE" if comparable else "NOT_COMPARABLE",
        })
    return projected


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
    if isinstance(exc, RunnerError):
        return str(exc)
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
        if isinstance(current, dict):
            if segment not in current:
                return None
            current = current[segment]
        elif isinstance(current, list) and segment.isdigit():
            index = int(segment)
            if index >= len(current):
                return None
            current = current[index]
        else:
            return None
    return current


def _assert_response(body: Any, assertions: dict[str, Any] | None, response_headers: dict[str, str] | None = None) -> str | None:
    if not assertions:
        return None
    for name, expected in assertions.get("headers_equals", {}).items():
        if not isinstance(name, str) or not isinstance(expected, str):
            return "ASSERTION_HEADER_INVALID"
        actual = next((value for key, value in (response_headers or {}).items() if key.lower() == name.lower()), None)
        if actual != expected:
            return "ASSERTION_HEADER_MISMATCH"
    if not isinstance(body, (dict, list)):
        return "RESPONSE_JSON_REQUIRED"
    alternatives = assertions.get("one_of", [])
    if alternatives:
        if not isinstance(alternatives, list) or not any(_assert_response(body, item) is None for item in alternatives if isinstance(item, dict)):
            return "ASSERTION_NO_ALLOWED_ENVELOPE"
    for path in assertions.get("required_paths", []):
        if _path_value(body, path) is None:
            return "ASSERTION_REQUIRED_PATH"
    for path in assertions.get("forbidden_paths", []):
        if _path_value(body, path) is not None:
            return "ASSERTION_FORBIDDEN_PATH"
    for path, expected in assertions.get("equals", {}).items():
        if _path_value(body, path) != expected:
            return "ASSERTION_VALUE_MISMATCH"
    for rule in assertions.get("contains", []):
        if not isinstance(rule, dict) or not isinstance(rule.get("path"), str) or not isinstance(rule.get("equals"), dict):
            return "ASSERTION_CONTAINS_INVALID"
        collection = _path_value(body, rule["path"])
        if not isinstance(collection, list) or not any(isinstance(item, dict) and all(item.get(key) == value for key, value in rule["equals"].items()) for item in collection):
            return "ASSERTION_COLLECTION_MISMATCH"
    return None


def _request(base_url: str, case: Case, timeout: float, tls_context: ssl.SSLContext | None = None, credentials: dict[str, str] | None = None) -> tuple[int, int, Any, str | None, dict[str, str]]:
    target = urljoin(base_url.rstrip("/") + "/", case.path.lstrip("/"))
    if not _same_origin(base_url, target):
        raise RunnerError("DESTINATION_NOT_ALLOWED")
    payload = None
    headers = {"Accept": "application/json", "User-Agent": "Famedic-IT0061-Runner/1.0"}
    if case.bearer_alias:
        if credentials is None or case.bearer_alias not in credentials:
            raise CredentialProviderError("CREDENTIAL_ALIAS_UNAVAILABLE")
        headers["Authorization"] = "Bearer " + credentials[case.bearer_alias]
    if case.machine_alias:
        if credentials is None or case.machine_alias not in credentials:
            raise CredentialProviderError("CREDENTIAL_ALIAS_UNAVAILABLE")
        headers["X-Consumer-Credential"] = credentials[case.machine_alias]
    if case.body is not None:
        payload = json.dumps(case.body, separators=(",", ":")).encode("utf-8")
        headers["Content-Type"] = "application/json"
    headers.update(case.headers or {})
    request = Request(target, data=payload, method=case.method, headers=headers)
    started = time.monotonic()
    response_headers: dict[str, str] = {}
    try:
        handlers: list[Any] = [NoRedirects()]
        if tls_context is not None:
            from urllib.request import HTTPSHandler
            handlers.append(HTTPSHandler(context=tls_context))
        with build_opener(*handlers).open(request, timeout=timeout) as response:
            status = response.status
            response_headers = dict(response.headers.items())
            raw = response.read(1024 * 1024)
            try:
                body = json.loads(raw.decode("utf-8")) if raw else None
            except (UnicodeDecodeError, json.JSONDecodeError):
                body = None
    except HTTPError as exc:
        status = exc.code
        response_headers = dict(exc.headers.items()) if exc.headers else {}
        try:
            raw = exc.read(1024 * 1024)
            body = json.loads(raw.decode("utf-8")) if raw else None
        except (OSError, UnicodeDecodeError, json.JSONDecodeError):
            body = None
    assertion_error = _assert_response(body, case.assertions, response_headers)
    return status, round((time.monotonic() - started) * 1000), body, assertion_error, response_headers


def _resolve_path(path: str, bodies: dict[str, Any]) -> str:
    pattern = re.compile(r"\{\{([A-Za-z0-9_-]+)\.([A-Za-z0-9_.]+)\}\}")
    def replace(match: re.Match[str]) -> str:
        value = _path_value(bodies.get(match.group(1)), match.group(2))
        if type(value) is not int or value <= 0:
            raise RunnerError("CLEANUP_SOURCE_ID_MISSING")
        return str(value)
    return pattern.sub(replace, path)


def run(plan: Path, base_url: str, output: Path, timeout: float = 5.0, mode: str = "local", credential_command: str | None = None, credential_values: dict[str, str] | None = None, release_source: Path | None = None, inventory_command: str | None = None) -> int:
    data = json.loads(plan.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise RunnerError("PLAN_INVALID")
    if mode == "qa" and data.get("id_source") == "local-synthetic-only":
        raise RunnerError("QA_PLAN_REQUIRES_RUNTIME_INVENTORY")
    requests, cleanup = load_plan(plan)
    metadata = data.get("qa_guards", {}) if isinstance(data.get("qa_guards", {}), dict) else {}
    tls_context = validate_mode(mode, base_url, metadata)
    if mode == "qa":
        if release_source is None or not release_source.is_file():
            raise RunnerError("RELEASE_SOURCE_REQUIRED")
        source = json.loads(release_source.read_text(encoding="utf-8"))
        if source.get("active_sha", "").lower() != metadata.get("expected_release_sha", "").lower():
            raise RunnerError("RELEASE_SHA_MISMATCH")
    if any(case.method == "DELETE" for case in requests + cleanup):
        if mode == "qa" and inventory_command is None:
            raise RunnerError("INVENTORY_PROVIDER_REQUIRED")
    inventory_provider = InventoryProvider(inventory_command) if inventory_command else None
    if mode == "qa" and any(case.writes for case in requests):
        if metadata.get("qa_writes_authorized") != "1":
            raise RunnerError("QA_WRITES_NOT_AUTHORIZED")
    if mode == "qa" and any(not case.assertions for case in requests):
        raise RunnerError("ASSERTIONS_REQUIRED")
    if any(case.case_id == "B25" and case.writes for case in requests):
        if metadata.get("leo_negative_writes_authorized") != "1":
            raise RunnerError("LEO_NEGATIVE_WRITES_NOT_AUTHORIZED")
    if mode == "qa" and any(case.writes for case in requests) and not cleanup:
        raise RunnerError("CLEANUP_PLAN_REQUIRED")
    aliases = {alias for case in requests + cleanup for alias in (case.bearer_alias, case.machine_alias) if alias}
    credential_scenario = data.get("credential_scenario", "functional-window-2")
    if not isinstance(credential_scenario, str):
        raise RunnerError("CREDENTIAL_SCENARIO_INVALID")
    credential_provider = CredentialProvider(credential_command, credential_values, credential_scenario) if aliases else None
    credentials = credential_provider.load(aliases) if credential_provider else None
    results: list[dict[str, Any]] = []
    cleanup_results: list[dict[str, Any]] = []
    response_bodies: dict[str, Any] = {}
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
                if case.method == "DELETE":
                    if inventory_provider is None or not case.inventory_ref:
                        raise InventoryProviderError("INVENTORY_PROVIDER_REQUIRED")
                    ref = data.get("inventory_records", {}).get(case.inventory_ref)
                    if not isinstance(ref, dict):
                        raise InventoryProviderError("INVENTORY_RECORD_MISSING")
                    resource_id = int(urlsplit(case.path).path.rsplit("/", 1)[-1])
                    inventory_provider.verify(data.get("inventory", {}).get("namespace", ""), resource_id, ref, metadata.get("expected_release_sha", ""))
                status, duration, body, assertion_error, _headers = _request(base_url, case, timeout, tls_context, credentials)
                response_bodies[case.case_id] = body
                ok = status in case.expected and assertion_error is None
                if ok and case.register_after_success:
                    if inventory_provider is None or not case.inventory_ref:
                        raise InventoryProviderError("INVENTORY_PROVIDER_REQUIRED")
                    ref = data.get("inventory_records", {}).get(case.inventory_ref)
                    if not isinstance(ref, dict):
                        raise InventoryProviderError("INVENTORY_RECORD_MISSING")
                    dynamic_id = _path_value(body, case.register_id_path or "data.item.id")
                    if type(dynamic_id) is not int or dynamic_id <= 0:
                        raise InventoryProviderError("HTTP_RESOURCE_ID_MISSING")
                    inventory_provider.register(data.get("inventory", {}).get("namespace", ""), dynamic_id, ref, case.case_id, metadata.get("expected_release_sha", ""))
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
                cleanup_case = case
                if case.source_case and case.source_case not in response_bodies:
                    omitted = {"id": case.case_id, "ok": True, "status": "NOT_RUN", "omitted": "RESOURCE_NOT_CREATED"}
                    cleanup_results.append(omitted)
                    if case.functional_case:
                        results.append({**omitted, "phase": "cleanup"})
                    continue
                if "{{" in case.path:
                    try:
                        resolved = _resolve_path(case.path, response_bodies)
                    except RunnerError as exc:
                        if str(exc) == "CLEANUP_SOURCE_ID_MISSING":
                            omitted = {"id": case.case_id, "ok": True, "status": "NOT_RUN", "omitted": "RESOURCE_NOT_CREATED"}
                            cleanup_results.append(omitted)
                            if case.functional_case:
                                results.append({**omitted, "phase": "cleanup"})
                            continue
                        raise
                    cleanup_case = Case(case.case_id, case.method, resolved, case.expected, case.body, case.writes, case.bearer_alias, case.machine_alias, case.assertions, case.inventory_ref, case.source_case, case.register_after_success, case.unregister_after_success, case.headers, case.register_id_path, case.functional_case)
                if cleanup_case.method == "DELETE":
                    ref = data.get("inventory_records", {}).get(case.inventory_ref or "")
                    if not isinstance(ref, dict):
                        raise InventoryProviderError("INVENTORY_RECORD_MISSING")
                    if not case.source_case and ref.get("created") is False:
                        omitted = {"id": case.case_id, "ok": True, "status": "NOT_RUN", "omitted": "RESOURCE_NOT_CREATED"}
                        cleanup_results.append(omitted)
                        if case.functional_case:
                            results.append({**omitted, "phase": "cleanup"})
                        continue
                    if inventory_provider is None:
                        raise InventoryProviderError("INVENTORY_PROVIDER_REQUIRED")
                    resource_id = int(urlsplit(cleanup_case.path).path.rsplit("/", 1)[-1])
                    inventory_provider.verify(data.get("inventory", {}).get("namespace", ""), resource_id, ref, metadata.get("expected_release_sha", ""))
                # Cleanup gets its own floor so a short functional timeout cannot
                # turn a guaranteed cleanup attempt into a secondary timeout.
                status, duration, _body, assertion_error, _headers = _request(base_url, cleanup_case, max(timeout, 5.0), tls_context, credentials)
                ok = status in case.expected and assertion_error is None
                if ok and case.unregister_after_success:
                    if inventory_provider is None or not case.inventory_ref:
                        raise InventoryProviderError("INVENTORY_PROVIDER_REQUIRED")
                    ref = data.get("inventory_records", {}).get(case.inventory_ref)
                    if not isinstance(ref, dict):
                        raise InventoryProviderError("INVENTORY_RECORD_MISSING")
                    resource_id = int(urlsplit(cleanup_case.path).path.rsplit("/", 1)[-1])
                    inventory_provider.unregister(data.get("inventory", {}).get("namespace", ""), resource_id, ref, metadata.get("expected_release_sha", ""))
                cleanup_result = {"id": case.case_id, "http_status": status, "ok": ok, "duration_ms": duration}
                cleanup_results.append(cleanup_result)
                if case.functional_case:
                    results.append({"id": case.case_id, "phase": "cleanup", "method": case.method, "path": cleanup_case.path, "http_status": status, "ok": ok, "duration_ms": duration})
                if not ok and exit_code == 0:
                    exit_code = 1
            except BaseException as exc:
                cleanup_results.append({"id": case.case_id, "ok": False, "error": _project_error(exc)})
                exit_code = 1
        if credential_provider and aliases:
            try:
                credential_provider.revoke(aliases)
                cleanup_results.append({"id": "credential-revocation", "ok": True})
            except BaseException as exc:
                cleanup_results.append({"id": "credential-revocation", "ok": False, "error": _project_error(exc)})
                exit_code = 1
        signal.signal(signal.SIGINT, old)

    cleanup_status = "CLEANUP_FAILED" if any(not item.get("ok", False) for item in cleanup_results) else "PASS"
    if cleanup_status == "CLEANUP_FAILED":
        exit_code = 1
    projected = {"schema": "it0061-functional-window-2-http.v1", "exit_code": exit_code, "interrupted": interrupted, "cleanup_status": cleanup_status, "requests": results, "cleanup": cleanup_results}
    successful_cases = {item["id"] for item in results if item.get("phase") is None and item.get("ok") is True}
    projected["comparisons"] = _project_comparisons(data.get("comparisons", []), response_bodies, successful_cases)
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
    parser.add_argument("--release-source", type=Path, help="Verified JSON source containing active_sha; required in QA")
    parser.add_argument("--inventory-command", help="Inventory verifier used before every DELETE")
    args = parser.parse_args(argv)
    try:
        return run(args.plan, args.base_url, args.output, args.timeout, args.mode, args.credential_command, None, args.release_source, args.inventory_command)
    except (OSError, RunnerError, json.JSONDecodeError) as exc:
        print(f"BLOCKED: {type(exc).__name__}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
