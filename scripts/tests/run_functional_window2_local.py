#!/usr/bin/env python3
"""Run the complete functional-window-2 plan against a local HTTP double."""
from __future__ import annotations

import json
import importlib.util
import os
import subprocess
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PLAN = Path(os.environ.get("FW2_PLAN", ROOT / "docs/Akubica/v1.3.0/functional-window-2-qa-plan.json"))
RUNNER = ROOT / "scripts/functional_window2_http.py"
PREPARE = ROOT / "scripts/local_window2_positive_check.php"


class Handler(BaseHTTPRequestHandler):
    seen: list[tuple[str, str]] = []
    cart_posts = 0

    def log_message(self, *_args):
        return

    def do_GET(self): self.respond()
    def do_POST(self): self.respond()
    def do_DELETE(self): self.respond()

    def respond(self):
        path = self.path.split("?", 1)[0]
        key = (self.command, path)
        self.seen.append(key)
        status = 200
        body = {"data": {}}
        if path == "/catalog/laboratory-tests/910003": body = {"data": {"pricing": {"comparison_status": "public_price_unavailable"}}}
        elif path == "/catalog/laboratory-tests": body = {"data": {"laboratory_tests": [{"id": 910003}], "pagination": {"current_page": 1}}}
        elif path == "/catalog/laboratory-tests/910006": body = {"data": {"id": 910006, "pricing": {}}}
        elif path == "/cart/items" and self.command == "POST":
            payload = self.rfile.read(int(self.headers.get("Content-Length", "0")))
            data = json.loads(payload or b"{}")
            Handler.cart_posts += 1
            if data.get("membership_plan_code") == "plan_inexistente": status, body = 422, {"error": {"code": "VALIDATION_ERROR"}}
            elif data.get("service_line") == "pharmacy": status, body = 503, {"error": {"code": "PHARMACY_SUSPENDED"}}
            elif data.get("laboratory_test_id") == 910022 or (data.get("service_line") == "medical_services" and "item_type" not in data): status, body = 403, {"error": {"code": "CONSUMER_NOT_AUTHORIZED"}}
            elif data.get("membership_plan_code") == "basic_annual" and Handler.cart_posts == 2: status, body = 409, {"error": {"code": "MEMBERSHIP_ALREADY_ACTIVE"}}
            else: status, body = 201, {"data": {"id": 910006}}
        elif path == "/cart": body = {"data": {"items": [{"laboratory_test_id": 910006}]}}
        elif path == "/memberships/plans": body = {"data": {"plans": [{"code": "basic_annual"}]}}
        elif path == "/memberships/status":
            if not self.headers.get("Authorization"): status, body = 401, {"error": {"code": "UNAUTHENTICATED"}}
            else: body = {"data": {"status": "active", "source": "individual", "ineligibility_reason": "membership_already_active"}}
        elif path == "/checkout/payment-link": status, body = 403, {"error": {"code": "CONSUMER_NOT_AUTHORIZED"}}
        elif path == "/orders": body = {"data": {"orders": [{"id": 910100}], "pagination": {"current_page": 1}}}
        elif path == "/orders/910101/status": status, body = 403, {"error": {"code": "FORBIDDEN"}}
        elif path == "/user/family": body = {"data": {"family": [{"id": 910200, "relationship": "child", "is_principal": False}]}}
        elif path == "/catalog/laboratory-brands": body = {"data": {"brands": ["olab"]}}
        elif path == "/cart/items/910006" or path == "/cart/items/910020": status, body = 200, {"data": {}}
        elif path == "/cart/items/910021": status, body = 200, {"data": {}}
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(json.dumps(body).encode())


def run(cmd: list[str], env: dict[str, str], input_text: str | None = None) -> subprocess.CompletedProcess[str]:
    return subprocess.run(cmd, cwd=ROOT, env=env, input=input_text, text=True, capture_output=True, check=False)


def windows_path(path: Path) -> str:
    value = str(path)
    if value.startswith("/mnt/c/"):
        return "C:/" + value[7:]
    return value


def main() -> int:
    env = os.environ.copy()
    php_bin = "/mnt/c/Program Files/php/php.exe" if Path("/mnt/c/Program Files/php/php.exe").exists() else "php"
    env.update({"APP_ENV": "testing", "APP_KEY": "base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=", "DB_CONNECTION": "mysql", "DB_HOST": "127.0.0.1", "DB_PORT": "13307", "DB_DATABASE": "famedic_rc2_it0061_window2_testing", "DB_USERNAME": "famedic_rc2_test", "DB_PASSWORD": "famedic_rc2_test_pw", "CACHE_STORE": "array", "SESSION_DRIVER": "array", "QUEUE_CONNECTION": "sync", "FAMEDIC_RC2_LOCAL_MYSQL": "1", "FAMEDIC_REGISTERED_NATIVE_FIXTURES": "1", "FAMEDIC_RC2_ACTIVE_CAMPAIGN_FAKE": "1", "ACTIVE_CAMPAIGN_API_ENDPOINT": "https://activecampaign.invalid", "ACTIVE_CAMPAIGN_API_TOKEN": "synthetic-test-token", "APP_CONFIG_CACHE": windows_path(ROOT / "storage/framework/cache/functional-window2-custody-config.php")})
    Handler.seen = []
    Handler.cart_posts = 0
    server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    output = ROOT / "storage/framework/testing-functional-window2-report.json"
    try:
        spec = importlib.util.spec_from_file_location("functional_window2_http", RUNNER)
        module = importlib.util.module_from_spec(spec)
        assert spec and spec.loader
        sys.modules["functional_window2_http"] = module
        spec.loader.exec_module(module)
        values = {"machine_native": "synthetic-machine-native", "machine_leo": "synthetic-machine-leo", "native_membership_regular": "synthetic-native-bearer", "leo_membership_none": "synthetic-leo-bearer"}
        credential_command = os.environ.get("FW2_CREDENTIAL_COMMAND")
        inventory_command = os.environ.get("FW2_INVENTORY_COMMAND")
        exit_code = module.run(PLAN, f"http://127.0.0.1:{server.server_port}", output, mode="local", credential_command=credential_command, credential_values=None if credential_command else values, inventory_command=inventory_command)
        if exit_code != 0:
            diagnostic = json.loads(output.read_text()) if output.exists() else {}
            print(json.dumps({"runner_failed": exit_code, "cleanup_status": diagnostic.get("cleanup_status"), "request_count": len(diagnostic.get("requests", [])), "cleanup_count": len(diagnostic.get("cleanup", [])), "errors": [item.get("error") for item in diagnostic.get("requests", []) + diagnostic.get("cleanup", []) if item.get("error")]}, sort_keys=True), file=sys.stderr)
            return 1
        report = json.loads(output.read_text())
        expected = [(item["method"], item["path"].split("?", 1)[0]) for item in json.loads(PLAN.read_text())["requests"]]
        expected += [(item["method"], item["path"].split("?", 1)[0]) for item in json.loads(PLAN.read_text())["cleanup"]]
        if Handler.seen != expected or len(report["requests"]) != 22 or len(report["cleanup"]) != 3 or report["cleanup_status"] != "PASS":
            print("report/order/count/cleanup mismatch", file=sys.stderr)
            return 1
        print(json.dumps({"status": "pass", "functional_requests": 22, "cleanup_requests": 2, "credential_revoke": 1, "http_order_exact": True, "secret_projection": False}))
        return 0
    finally:
        server.shutdown()
        server.server_close()
        if output.exists(): output.unlink()


if __name__ == "__main__":
    raise SystemExit(main())
