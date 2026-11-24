import json
import subprocess
import sys
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

from scripts.functional_window2_http import RunnerError, run, validate_mode
from scripts.functional_window2_preconditions import validate


ROOT = Path(__file__).parents[2]
RUNNER = ROOT / "scripts" / "functional_window2_http.py"


class Handler(BaseHTTPRequestHandler):
    calls = []
    mode = "ok"

    def log_message(self, *_args):
        return

    def do_GET(self):
        self.calls.append(("GET", self.path))
        if self.path == "/assert":
            payload = b'{"data":{"status":"ok"},"secret":"synthetic-secret"}'
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(payload)))
            self.end_headers()
            self.wfile.write(payload)
            return
        if self.path == "/interrupt":
            import time
            time.sleep(2)
            self.send_response(200)
        elif self.path == "/fail":
            self.send_response(503)
        elif self.path == "/slow":
            import time
            time.sleep(0.2)
            self.send_response(200)
        else:
            self.send_response(200)
        self.end_headers()

    def do_POST(self):
        self.calls.append(("POST", self.path))
        self.send_response(500 if self.path == "/cleanup-fail" else 204)
        self.end_headers()


class HttpRunnerTests(unittest.TestCase):
    def setUp(self):
        Handler.calls = []
        self.server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.base = f"http://127.0.0.1:{self.server.server_port}"

    def tearDown(self):
        self.server.shutdown()
        self.thread.join(timeout=2)
        self.server.server_close()

    def run_plan(self, plan, timeout=1, provider=None):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            plan_path = root / "plan.json"
            out = root / "out.json"
            plan_path.write_text(json.dumps(plan), encoding="utf-8")
            command = [sys.executable, str(RUNNER), str(plan_path), "--base-url", self.base, "--output", str(out), "--timeout", str(timeout)]
            if provider:
                command.extend(["--credential-command", str(provider)])
            proc = subprocess.run(command, capture_output=True, text=True)
            return proc, json.loads(out.read_text(encoding="utf-8"))

    def test_exact_order_and_cleanup(self):
        proc, report = self.run_plan({"requests": [{"id": "B03", "method": "GET", "path": "/a", "expected_status": 200}, {"id": "B06b", "method": "POST", "path": "/b", "expected_status": 204}], "cleanup": [{"id": "cleanup", "method": "POST", "path": "/cleanup", "expected_status": 204}]})
        self.assertEqual(proc.returncode, 0)
        self.assertEqual(Handler.calls, [("GET", "/a"), ("POST", "/b"), ("POST", "/cleanup")])
        self.assertTrue(all(x["ok"] for x in report["requests"] + report["cleanup"]))
        self.assertEqual(report["cleanup_status"], "PASS")

    def test_http_failure_keeps_failure_and_runs_cleanup(self):
        proc, report = self.run_plan({"requests": [{"id": "B22", "method": "GET", "path": "/fail", "expected_status": 200}], "cleanup": [{"id": "cleanup", "method": "POST", "path": "/cleanup", "expected_status": 204}]})
        self.assertEqual(proc.returncode, 1)
        self.assertFalse(report["requests"][0]["ok"])
        self.assertTrue(report["cleanup"][0]["ok"])
        self.assertEqual(report["cleanup_status"], "PASS")

    def test_timeout_keeps_failure_and_runs_cleanup(self):
        proc, report = self.run_plan({"requests": [{"id": "B07", "method": "GET", "path": "/slow", "expected_status": 200}], "cleanup": [{"id": "cleanup", "method": "POST", "path": "/cleanup", "expected_status": 204}]}, timeout=0.01)
        self.assertEqual(proc.returncode, 1)
        self.assertEqual(report["requests"][0]["error"], "TIMEOUT")
        self.assertTrue(report["cleanup"][0]["ok"])
        self.assertEqual(report["cleanup_status"], "PASS")

    def test_cleanup_failure_is_explicit_and_nonzero(self):
        proc, report = self.run_plan({"requests": [{"id": "B06b", "method": "POST", "path": "/b", "expected_status": 204}], "cleanup": [{"id": "cleanup", "method": "POST", "path": "/cleanup-fail", "expected_status": 204}]})
        self.assertEqual(proc.returncode, 1)
        self.assertEqual(report["cleanup_status"], "CLEANUP_FAILED")

    def test_non_local_target_is_blocked(self):
        with tempfile.TemporaryDirectory() as d:
            plan = Path(d) / "p.json"
            out = Path(d) / "o.json"
            plan.write_text(json.dumps({"requests": [{"id": "B03", "method": "GET", "path": "/a", "expected_status": 200}]}), encoding="utf-8")
            proc = subprocess.run([sys.executable, str(RUNNER), str(plan), "--base-url", "https://qa.example", "--output", str(out)], capture_output=True, text=True)
            self.assertEqual(proc.returncode, 2)
            self.assertIn("BLOCKED", proc.stderr)

    def test_qa_mode_validates_allowlist_tls_and_release_without_connecting(self):
        context = validate_mode("qa", "https://famedic-otp.on-forge.com", {
            "target_env": "staging",
            "expected_release_sha": "e2ff4365cda5df6cbef605e97414d1f071ddf9a5",
            "qa_writes_authorized": "1",
            "leo_negative_writes_authorized": "1",
            "https_verified": True,
        })
        self.assertIsNotNone(context)
        with self.assertRaises(RunnerError):
            validate_mode("qa", "http://famedic-otp.on-forge.com", {"https_verified": True})
        with self.assertRaises(RunnerError):
            validate_mode("qa", "https://unknown.example", {"https_verified": True})
        with self.assertRaises(RunnerError):
            validate_mode("qa", "https://famedic-otp.on-forge.com", {
                "target_env": "staging", "expected_release_sha": "not-a-sha", "https_verified": True,
            })

    def test_qa_mode_requires_exact_write_guard(self):
        with self.assertRaises(RunnerError):
            validate_mode("qa", "https://staging.famedic.com.mx", {
                "target_env": "staging",
                "expected_release_sha": "e2ff4365cda5df6cbef605e97414d1f071ddf9a5",
                "qa_writes_authorized": "true",
                "leo_negative_writes_authorized": "0",
                "https_verified": True,
            })

    def test_secret_never_enters_projected_output(self):
        proc, report = self.run_plan({"requests": [{"id": "B03", "method": "GET", "path": "/a", "expected_status": 200}], "cleanup": []})
        self.assertEqual(proc.returncode, 0)
        self.assertNotIn("Authorization", json.dumps(report))
        self.assertNotIn("Bearer", json.dumps(report))

    def test_custody_injects_headers_and_assertions_without_projecting_secret(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            provider = root / "provider.py"
            provider.write_text(
                "import json,sys; x=json.load(sys.stdin); "
                "assert x['aliases']==['bearer_leo','machine_leo']; "
                "sys.stderr.write('synthetic-stderr-secret'); "
                "print(json.dumps({'revoked':x['aliases']} if x['action']=='revoke' else {'bearer_leo':'synthetic-bearer','machine_leo':'synthetic-machine'}))\n",
                encoding="utf-8",
            )
            proc, report = self.run_plan({
                "selection": {"authorized_ids": ["B09"], "expected_request_count": 1},
                "requests": [{
                    "id": "B09", "method": "GET", "path": "/assert", "expected_status": 200,
                    "auth": {"bearer_alias": "bearer_leo", "machine_alias": "machine_leo"},
                    "assertions": {"equals": {"data.status": "ok"}, "forbidden_paths": ["data.missing"]},
                }],
                "cleanup": [],
            }, provider=f"{sys.executable} {provider}")
            self.assertEqual(proc.returncode, 0)
            self.assertTrue(report["requests"][0]["ok"])
            serialized = json.dumps(report)
            self.assertNotIn("synthetic-bearer", serialized)
            self.assertNotIn("synthetic-machine", serialized)
            self.assertNotIn("synthetic-secret", serialized)
            self.assertNotIn("synthetic-stderr-secret", proc.stderr)

    def test_membership_scenario_is_sent_to_custody_read_and_revoke(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            provider = root / "provider.py"
            provider.write_text(
                "import json,sys; x=json.load(sys.stdin); "
                "assert x['scenario']=='functional-window-2-memberships'; "
                "print(json.dumps({'revoked':x['aliases']} if x['action']=='revoke' else {'bearer':'synthetic-bearer','machine':'synthetic-machine'}))\n",
                encoding="utf-8",
            )
            proc, report = self.run_plan({
                "credential_scenario": "functional-window-2-memberships",
                "requests": [{
                    "id": "B08", "method": "GET", "path": "/assert", "expected_status": 200,
                    "auth": {"bearer_alias": "bearer", "machine_alias": "machine"},
                    "assertions": {"equals": {"data.status": "ok"}},
                }],
                "cleanup": [],
            }, provider=f"{sys.executable} {provider}")
            self.assertEqual(proc.returncode, 0)
            self.assertTrue(report["requests"][0]["ok"])

    def test_assertion_failure_is_not_pass(self):
        proc, report = self.run_plan({
            "requests": [{"id": "B09", "method": "GET", "path": "/assert", "expected_status": 200, "assertions": {"equals": {"data.status": "wrong"}}}],
            "cleanup": [],
        })
        self.assertEqual(proc.returncode, 1)
        self.assertFalse(report["requests"][0]["ok"])
        self.assertEqual(report["requests"][0]["assertion_error"], "ASSERTION_VALUE_MISMATCH")

    def test_plan_rejects_unconsumed_body_key_before_http(self):
        with tempfile.TemporaryDirectory() as d:
            plan = Path(d) / "plan.json"
            plan.write_text(json.dumps({
                "requests": [{
                    "id": "B19", "method": "POST", "path": "/cart/items",
                    "body": {"service_line": "medical_services"}, "expected_status": 409,
                }],
                "cleanup": [],
            }), encoding="utf-8")
            with self.assertRaisesRegex(RunnerError, "JSON_BODY_KEY_INVALID"):
                from scripts.functional_window2_http import load_plan
                load_plan(plan)
            self.assertEqual(Handler.calls, [])

    def test_interrupt_keeps_failure_and_runs_cleanup(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            plan = root / "plan.json"
            out = root / "out.json"
            plan.write_text(json.dumps({
                "requests": [{"id": "B07", "method": "GET", "path": "/interrupt", "expected_status": 200}],
                "cleanup": [{"id": "cleanup", "method": "POST", "path": "/cleanup", "expected_status": 204}],
            }), encoding="utf-8")
            import signal
            previous = signal.signal(signal.SIGALRM, lambda _signum, _frame: (_ for _ in ()).throw(KeyboardInterrupt()))
            signal.setitimer(signal.ITIMER_REAL, 0.03)
            try:
                exit_code = run(plan, self.base, out)
            finally:
                signal.setitimer(signal.ITIMER_REAL, 0)
                signal.signal(signal.SIGALRM, previous)
            report = json.loads(out.read_text(encoding="utf-8"))
            self.assertEqual(exit_code, 130)
            self.assertTrue(report["interrupted"])
            self.assertEqual(report["cleanup_status"], "PASS")

    def test_synthetic_blocked_preconditions_are_namespace_isolated_and_literal(self):
        result = validate(ROOT / "docs" / "Akubica" / "v1.3.0" / "functional-window-2-preconditions.synthetic.json")
        self.assertEqual(result["resources"], 5)
        self.assertFalse(result["external_effects"])


if __name__ == "__main__":
    unittest.main()
