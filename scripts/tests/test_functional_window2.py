import json
import tempfile
import unittest
from pathlib import Path

from scripts.functional_window2 import WindowError, sanitize_log, sanitize_report, select_collection


COLLECTION = {
    "info": {"name": "synthetic"},
    "item": [
        {"name": "Fase B (B01–B26)", "event": [{"listen": "prerequest", "script": {"exec": ["guard"]}}], "item": [
            {"name": "B09 Estado de membresía (bearer_active)", "request": {"method": "GET", "url": "{{base_url}}/memberships/status"}},
            {"name": "B14 Rechazo con membresía individual activa", "request": {"method": "POST", "url": "{{base_url}}/cart/items"}},
        ]},
        {"name": "Regresión (R01–R08)", "item": [
            {"name": "R02b Nativo: metadatos de resultados", "request": {"method": "GET", "url": "{{base_url}}/orders/1/results"}},
            {"name": "R04 Familiares propios", "request": {"method": "GET", "url": "{{base_url}}/user/family"}},
            {"name": "R07a Error de validación tipado", "request": {"method": "POST", "url": "{{base_url}}/cart/items"}},
            {"name": "R07a2 Leo sin item_type", "request": {"method": "POST", "url": "{{base_url}}/cart/items"}},
            {"name": "R07b Sin Bearer", "request": {"method": "GET", "url": "{{base_url}}/cart/items"}},
        ]},
    ],
}


class FunctionalWindow2Tests(unittest.TestCase):
    def test_selection_rejects_incidental_scenario(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / "collection.json"
            output = root / "selected.json"
            source.write_text(json.dumps(COLLECTION), encoding="utf-8")
            with self.assertRaises(WindowError):
                select_collection(source, output, "R02b")

    def test_selection_counts_numeric_variant(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / "collection.json"
            output = root / "selected.json"
            source.write_text(json.dumps(COLLECTION), encoding="utf-8")
            result = select_collection(source, output, "R07")
            self.assertEqual(result["requests_selected"], 3)

    def test_selection_does_not_include_full_folder(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / "collection.json"
            output = root / "selected.json"
            source.write_text(json.dumps(COLLECTION), encoding="utf-8")
            result = select_collection(source, output, "B09,R04")
            self.assertEqual(result["scenario_ids"], ["B09", "R04"])
            selected = json.loads(output.read_text(encoding="utf-8"))
            names = [item["name"] for folder in selected["item"] for item in folder["item"]]
            self.assertEqual(names, ["B09 Estado de membresía (bearer_active)", "R04 Familiares propios"])

    def test_sanitizer_projects_allowed_fields_only(self):
        report = {"run": {"executions": [{
            "item": {"name": "B09 Estado de membresía (bearer_active)"},
            "request": {"method": "GET", "url": "{{base_url}}/memberships/status", "header": [{"key": "Authorization", "value": "Bearer synthetic-secret"}]},
            "response": {"code": 200, "responseTime": 12, "body": {"success": True, "data": {"status": "active"}}},
            "assertions": [{"error": None}],
        }]}}
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / "raw.json"
            output = root / "sanitized.json"
            source.write_text(json.dumps(report), encoding="utf-8")
            result = sanitize_report(source, output, {"B09"})
            self.assertEqual(result["cases"], 1)
            projected = json.loads(output.read_text(encoding="utf-8"))
            self.assertNotIn("Authorization", output.read_text(encoding="utf-8"))
            self.assertEqual(projected["cases"][0]["http_status"], 200)

    def test_sanitizer_keeps_failed_exit_and_assertion_result(self):
        report = {"run": {"executions": [{
            "item": {"name": "B09 Estado de membresía (bearer_active)"},
            "request": {"method": "GET", "url": "{{base_url}}/memberships/status"},
            "response": {"code": 403, "body": {"error": {"code": "CONSUMER_NOT_AUTHORIZED"}}},
            "assertions": [{"error": "expected 200"}],
        }]}}
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / "raw.json"
            output = root / "sanitized.json"
            source.write_text(json.dumps(report), encoding="utf-8")
            sanitize_report(source, output, {"B09"}, exit_code=1)
            projected = json.loads(output.read_text(encoding="utf-8"))
            self.assertEqual(projected["exit_code"], 1)
            self.assertEqual(projected["cases"][0]["assertions"][0]["result"], "FAIL")

    def test_sanitizer_rejects_missing_and_duplicate_requests(self):
        base = {
            "item": {"name": "B09 Estado de membresía (bearer_active)"},
            "request": {"method": "GET", "url": "{{base_url}}/memberships/status"},
            "response": {"code": 200, "body": {}},
            "assertions": [{"error": None}],
        }
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / "raw.json"
            output = root / "sanitized.json"
            source.write_text(json.dumps({"run": {"executions": [base, dict(base)]}}), encoding="utf-8")
            with self.assertRaises(WindowError):
                sanitize_report(source, output, {"B09"})

    def test_log_sanitizer_preserves_failure_exit_without_secret(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / "stderr.log"
            output = root / "sanitized.json"
            source.write_text("Assertion failed; Authorization: Bearer synthetic-secret\n", encoding="utf-8")
            result = sanitize_log(source, output, 1)
            projected = json.loads(output.read_text(encoding="utf-8"))
            self.assertEqual(result["exit_code"], 1)
            self.assertEqual(projected["exit_code"], 1)
            self.assertIn("REDACTION_APPLIED", projected["categories"])
            self.assertNotIn("synthetic-secret", output.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
