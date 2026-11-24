import json
import tempfile
import unittest
from pathlib import Path

from scripts.functional_window2_membership_cart_plan import PlanError, generate


ROOT = Path(__file__).resolve().parents[2]
TEMPLATE = ROOT / "docs/Akubica/v1.3.0/functional-window-2-memberships-cart-qa-plan.json"


class MembershipCartPlanTest(unittest.TestCase):
    def test_generates_from_verified_identity_and_reuses_b12_key_for_b17(self):
        sha = "a" * 40
        with tempfile.TemporaryDirectory() as directory:
            directory = Path(directory)
            source = directory / "release-source.json"
            output = directory / "plan.json"
            source.write_text(json.dumps({"status": "verified", "active_sha": sha}), encoding="utf-8")

            result = generate(TEMPLATE, source, output, "window-20261124")
            plan = json.loads(output.read_text(encoding="utf-8"))
            b12 = plan["requests"][0]["headers"]["Idempotency-Key"]
            b13 = plan["requests"][2]["headers"]["Idempotency-Key"]
            b17 = plan["requests"][3]["headers"]["Idempotency-Key"]

            self.assertEqual(result["sha"], sha)
            self.assertFalse(plan["template"])
            self.assertEqual(plan["qa_guards"]["expected_release_sha"], sha)
            self.assertEqual(b12, b17)
            self.assertNotEqual(b12, b13)
            rendered = output.read_text(encoding="utf-8")
            self.assertNotIn("{{RELEASE_SHA_FROM_IDENTITY}}", rendered)
            self.assertNotIn("{{B12_IDEMPOTENCY_KEY}}", rendered)
            self.assertNotIn("{{B13_IDEMPOTENCY_KEY}}", rendered)
            self.assertEqual(plan["cleanup"][0]["functional_case"], True)

    def test_rejects_unverified_or_invalid_release_identity_without_output(self):
        for payload in ({"status": "unverified", "active_sha": "a" * 40}, {"status": "verified", "active_sha": "stale"}):
            with self.subTest(payload=payload), tempfile.TemporaryDirectory() as directory:
                directory = Path(directory)
                source = directory / "release-source.json"
                output = directory / "plan.json"
                source.write_text(json.dumps(payload), encoding="utf-8")
                with self.assertRaises(PlanError):
                    generate(TEMPLATE, source, output, "window-20261124")
                self.assertFalse(output.exists())


if __name__ == "__main__":
    unittest.main()
