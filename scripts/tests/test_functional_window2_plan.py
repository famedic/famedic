import json
import sys
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

from scripts.functional_window2_http import run
from scripts.functional_window2_plan import PlanError, generate


ROOT = Path(__file__).parents[2]
TEMPLATE = ROOT / "docs/Akubica/v1.3.0/functional-window-2-qa-plan.json"
SHA = "2bcda828afefa0edfc898b1dca86400865c438cb"


class FunctionalWindow2PlanTests(unittest.TestCase):
    def inventory(self, root: Path) -> Path:
        ids = {"B03": 920003, "B06b": 920006, "B20": 920020, "B21": 920021, "B21x": 920022, "R01": 920100, "R01b": 920101}
        resources = {key: {"id": value, "namespace": "akubica-uat-batch2-a4-v1", "purpose": key, "owner": "customer-dedicated"} for key, value in ids.items()}
        path = root / "inventory.json"
        path.write_text(json.dumps({"namespace": "akubica-uat-batch2-a4-v1", "resources": resources}), encoding="utf-8")
        return path

    def release(self, root: Path, sha: str = SHA) -> Path:
        path = root / "release.json"
        path.write_text(json.dumps({"active_sha": sha, "template_sha": "template-test"}), encoding="utf-8")
        return path

    def test_generates_operational_plan_with_runtime_ids(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            output = root / "plan.json"
            result = generate(TEMPLATE, self.inventory(root), self.release(root), SHA, output)
            plan = json.loads(output.read_text(encoding="utf-8"))
            self.assertEqual(result["requests"], 22)
            self.assertEqual(plan["id_source"], "runtime-inventory")
            self.assertEqual(plan["qa_guards"]["expected_release_sha"], SHA)
            self.assertNotIn("910006", output.read_text(encoding="utf-8"))
            self.assertNotIn("910020", output.read_text(encoding="utf-8"))
            self.assertEqual(plan["cleanup"][0]["source_case"], "B06b")
            self.assertEqual(plan["requests"][10]["inventory_ref"], "B21")

    def test_old_sha_and_foreign_inventory_are_rejected_without_output(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            inventory = self.inventory(root)
            foreign = json.loads(inventory.read_text(encoding="utf-8"))
            foreign["resources"]["B20"]["namespace"] = "akubica-uat-v1"
            inventory.write_text(json.dumps(foreign), encoding="utf-8")
            with self.assertRaises(PlanError) as error:
                generate(TEMPLATE, inventory, self.release(root, "e2ff4365cda2a3b85acff3dd3e21ded8f309cd7b"), SHA, root / "plan.json")
            self.assertIn("RELEASE_SHA_MISMATCH", str(error.exception))
            self.assertFalse((root / "plan.json").exists())

    def test_missing_resource_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            inventory = json.loads(self.inventory(root).read_text(encoding="utf-8"))
            del inventory["resources"]["B20"]
            inventory_path = root / "inventory.json"
            inventory_path.write_text(json.dumps(inventory), encoding="utf-8")
            with self.assertRaises(PlanError) as error:
                generate(TEMPLATE, inventory_path, self.release(root), SHA, root / "plan.json")
            self.assertIn("INVENTORY_ID_MISSING:B20", str(error.exception))

    def test_runtime_id_is_captured_and_verified_before_delete(self):
        calls = []

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_args):
                return

            def do_POST(self):
                calls.append(("POST", self.path))
                body = b'{"data":{"id":4242}}'
                self.send_response(201)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            def do_DELETE(self):
                calls.append(("DELETE", self.path))
                self.send_response(204)
                self.end_headers()

        server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            plan = root / "plan.json"
            output = root / "result.json"
            provider = root / "inventory.py"
            plan.write_text(json.dumps({
                "inventory": {"namespace": "akubica-uat-batch2-a4-v1"},
                "inventory_records": {"B06b": {"purpose": "cart-item-created-by-B06b", "owner": "dedicated-native"}},
                "requests": [{"id": "B06b", "method": "POST", "path": "/cart/items", "expected_status": 201}],
                "cleanup": [{"id": "cleanup-b06", "method": "DELETE", "path": "/cart/items/{{B06b.data.id}}", "expected_status": 204, "inventory_ref": "B06b"}],
            }), encoding="utf-8")
            provider.write_text(
                "import json,sys; x=json.load(sys.stdin); "
                "print(json.dumps({'status':'verified','namespace':x['namespace'],'resource_id':x['resource_id'],'purpose':x['purpose'],'owner':x['owner']}))\n",
                encoding="utf-8",
            )
            code = run(plan, f"http://127.0.0.1:{server.server_port}", output, inventory_command=f"{sys.executable} {provider}")
            self.assertEqual(code, 0)
            self.assertEqual(calls, [("POST", "/cart/items"), ("DELETE", "/cart/items/4242")])
        server.shutdown()
        thread.join(timeout=2)
        server.server_close()

    def test_foreign_or_missing_inventory_blocks_delete(self):
        calls = []

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_args):
                return

            def do_DELETE(self):
                calls.append(self.path)
                self.send_response(204)
                self.end_headers()

        server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            plan = root / "plan.json"
            output = root / "result.json"
            provider = root / "inventory.py"
            plan.write_text(json.dumps({
                "inventory": {"namespace": "akubica-uat-batch2-a4-v1"},
                "inventory_records": {"B21": {"purpose": "cart-delete", "owner": "dedicated-native"}},
                "requests": [{"id": "B21", "method": "DELETE", "path": "/cart/items/7777", "expected_status": 204, "inventory_ref": "B21"}],
                "cleanup": [],
            }), encoding="utf-8")
            provider.write_text("import sys; sys.exit(1)\n", encoding="utf-8")
            code = run(plan, f"http://127.0.0.1:{server.server_port}", output, inventory_command=f"{sys.executable} {provider}")
            self.assertEqual(code, 1)
            self.assertEqual(calls, [])
        server.shutdown()
        thread.join(timeout=2)
        server.server_close()


if __name__ == "__main__":
    unittest.main()
