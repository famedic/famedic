import json
import os
import shlex
import sqlite3
import subprocess
import sys
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

from scripts.functional_window2_http import run
from scripts.functional_window2_plan import generate
from scripts.functional_window2_sources import inventory, release_source


ROOT = Path(__file__).parents[2]
TEMPLATE = ROOT / "docs/Akubica/v1.3.0/functional-window-2-qa-plan.json"


class Window2IntegrationTests(unittest.TestCase):
    def test_generated_plan_runner_inventory_and_custody_cover_22_requests(self):
        calls = []
        membership_attempts = [0]

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_args):
                return

            def send_json(self, status, body):
                payload = json.dumps(body).encode()
                self.send_response(status)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)

            def do_any(self):
                calls.append((self.command, self.path))
                if self.command == "DELETE":
                    return self.send_json(200, {"data": {}})
                if not self.headers.get("Authorization") and not self.headers.get("X-Api-Consumer-Credential"):
                    return self.send_json(401, {"error": {"code": "UNAUTHENTICATED"}})
                body = {}
                if self.command == "POST":
                    raw = self.rfile.read(int(self.headers.get("Content-Length", "0") or 0))
                    body = json.loads(raw or b"{}")
                path = self.path.split("?", 1)[0]
                if path == "/catalog/laboratory-tests/930003": return self.send_json(200, {"data": {"id": 930003, "pricing": {"comparison_status": "public_price_unavailable"}}})
                if path == "/catalog/laboratory-tests/930006": return self.send_json(200, {"data": {"id": 930006, "pricing": {}}})
                if path == "/catalog/laboratory-tests": return self.send_json(200, {"data": {"laboratory_tests": [{"id": 930003}], "pagination": {}}})
                if path == "/cart" and self.command == "GET": return self.send_json(200, {"data": {"items": [{"laboratory_test_id": 930006}], "totals": {"item_count": 1}}})
                if path == "/memberships/plans": return self.send_json(200, {"data": {"plans": [{}]}})
                if path == "/memberships/status": return self.send_json(200, {"data": {"status": "active", "source": "individual", "ineligibility_reason": "membership_already_active"}})
                if path == "/catalog/laboratory-brands": return self.send_json(200, {"data": []})
                if path == "/orders": return self.send_json(200, {"data": {"orders": [{}], "pagination": {}}})
                if path == "/orders/930101/status": return self.send_json(404, {"error": {"code": "NOT_FOUND"}})
                if path == "/user/family": return self.send_json(200, {"data": {"family": [{}]}})
                if path == "/cart/items" and body.get("laboratory_test_id") == 930006: return self.send_json(201, {"data": {"id": 930006}})
                if path == "/cart/items" and body.get("laboratory_test_id") == 930020: return self.send_json(201, {"data": {"id": 930020}})
                if path == "/cart/items" and body.get("laboratory_test_id") == 930022: return self.send_json(403, {"error": {"code": "CONSUMER_NOT_AUTHORIZED"}})
                if path == "/cart/items" and body.get("service_line") == "pharmacy": return self.send_json(503, {"error": {"code": "PHARMACY_SUSPENDED"}})
                if path == "/cart/items" and body.get("membership_plan_code") == "basic_annual":
                    membership_attempts[0] += 1
                    return self.send_json(409, {"error": {"code": "MEMBERSHIP_ALREADY_ACTIVE"}}) if membership_attempts[0] == 1 else self.send_json(201, {"data": {"id": 930019}})
                if path == "/cart/items" and body.get("membership_plan_code") == "plan_inexistente": return self.send_json(422, {"error": {"code": "VALIDATION_ERROR"}})
                if path == "/cart/items" and body.get("service_line") == "medical_services": return self.send_json(403, {"error": {"code": "CONSUMER_NOT_AUTHORIZED"}})
                if path == "/checkout/payment-link": return self.send_json(403, {"error": {"code": "CONSUMER_NOT_AUTHORIZED"}})
                return self.send_json(401, {"error": {"code": "UNAUTHENTICATED"}})

            do_GET = do_any
            do_POST = do_any
            do_DELETE = do_any

        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            db_path = root / "inventory.sqlite"
            with sqlite3.connect(db_path) as db:
                db.execute("CREATE TABLE window2_inventory (resource_key TEXT, resource_id INTEGER, namespace TEXT, owner TEXT, purpose TEXT, created INTEGER)")
                for key, resource_id in {"B03": 930003, "B06b": 930006, "B20": 930020, "B21": 930021, "B21x": 930022, "R01": 930100, "R01b": 930101}.items():
                    db.execute("INSERT INTO window2_inventory VALUES (?, ?, ?, ?, ?, 1)", (key, resource_id, "akubica-uat-batch2-a4-v1", "customer-dedicated", key))
                db.commit()
            release = root / "release.json"
            inv = root / "inventory.json"
            release.write_text(json.dumps(release_source(ROOT)), encoding="utf-8")
            inv.write_text(json.dumps(inventory(db_path)), encoding="utf-8")
            plan = root / "plan.json"
            generate(TEMPLATE, inv, release, release_source(ROOT)["active_sha"], plan)
            custody = root / "custody.py"
            custody.write_text("import json,sys; x=json.load(sys.stdin); print(json.dumps({'revoked':x['aliases']} if x['action']=='revoke' else {a:'synthetic-secret' for a in x['aliases']}))\n", encoding="utf-8")
            server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            old_db, old_root = os.environ.get("FW2_INVENTORY_DB"), os.environ.get("FW2_RELEASE_ROOT")
            os.environ["FW2_INVENTORY_DB"], os.environ["FW2_RELEASE_ROOT"] = str(db_path), str(ROOT)
            try:
                probe = subprocess.run([sys.executable, str(ROOT / "scripts/functional_window2_inventory.py")], input=json.dumps({"action": "verify", "namespace": "akubica-uat-batch2-a4-v1", "resource_id": 930021, "purpose": "B21", "owner": "customer-dedicated", "expected_sha": release_source(ROOT)["active_sha"]}), text=True, capture_output=True)
                if probe.returncode != 0:
                    print("inventory-probe", probe.stdout, probe.stderr)
                code = run(plan, f"http://127.0.0.1:{server.server_port}", root / "report.json", credential_command=shlex.join([sys.executable, str(custody)]), inventory_command=shlex.join([sys.executable, str(ROOT / 'scripts/functional_window2_inventory.py')]))
            finally:
                if old_db is None: os.environ.pop("FW2_INVENTORY_DB", None)
                else: os.environ["FW2_INVENTORY_DB"] = old_db
                if old_root is None: os.environ.pop("FW2_RELEASE_ROOT", None)
                else: os.environ["FW2_RELEASE_ROOT"] = old_root
                server.shutdown(); thread.join(timeout=2); server.server_close()
            report = json.loads((root / "report.json").read_text(encoding="utf-8"))
            self.assertEqual(code, 0)
            self.assertEqual(len(report["requests"]), 22)
            self.assertEqual(len(report["cleanup"]), 3)
            self.assertEqual(report["cleanup_status"], "PASS")
            self.assertEqual(sum(1 for method, _ in calls if method == "DELETE"), 3)


if __name__ == "__main__":
    unittest.main()
