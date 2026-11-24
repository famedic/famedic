#!/usr/bin/env python3
"""Generate an operational QA plan from the versioned window template.

The template is never executed directly.  QA plans are bound to a verified
release source and a preparation inventory; local fixture ids are rejected
from the generated artifact.
"""
from __future__ import annotations

import argparse
import copy
import json
import re
import sys
from pathlib import Path
from typing import Any


SHA_RE = re.compile(r"^[0-9a-f]{40}$", re.I)
NAMESPACE = "akubica-uat-batch2-a4-v1"
LOCAL_IDS = {910006, 910020}
ID_MAP = {
    910003: "B03",
    910006: "B06b",
    910020: "B20",
    910021: "B21",
    910022: "B21x",
    910100: "R01",
    910101: "R01b",
}


class PlanError(ValueError):
    pass


def _load(path: Path) -> dict[str, Any]:
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise PlanError("JSON_OBJECT_REQUIRED")
    return data


def _inventory(path: Path) -> dict[str, Any]:
    data = _load(path)
    if data.get("namespace") != NAMESPACE:
        raise PlanError("INVENTORY_NAMESPACE_INVALID")
    resources = data.get("resources")
    if not isinstance(resources, dict) or not resources:
        raise PlanError("INVENTORY_RESOURCES_REQUIRED")
    for key, record in resources.items():
        if not isinstance(record, dict) or record.get("namespace") != NAMESPACE:
            raise PlanError(f"INVENTORY_RESOURCE_SCOPE_INVALID:{key}")
        if not record.get("purpose") or not record.get("owner"):
            raise PlanError(f"INVENTORY_RESOURCE_PROVENANCE_MISSING:{key}")
    return data


def _replace_ids(value: Any, resources: dict[str, Any]) -> Any:
    if isinstance(value, dict):
        return {key: _replace_ids(item, resources) for key, item in value.items()}
    if isinstance(value, list):
        return [_replace_ids(item, resources) for item in value]
    if isinstance(value, str):
        for local_id, resource_key in ID_MAP.items():
            marker = str(local_id)
            if marker in value:
                record = resources.get(resource_key)
                if not isinstance(record, dict) or not isinstance(record.get("id"), int):
                    raise PlanError(f"INVENTORY_ID_MISSING:{resource_key}")
                value = re.sub(rf"(?<![0-9]){marker}(?![0-9])", str(record["id"]), value)
        return value
    if isinstance(value, int) and value in ID_MAP:
        record = resources.get(ID_MAP[value])
        if not isinstance(record, dict) or not isinstance(record.get("id"), int):
            raise PlanError(f"INVENTORY_ID_MISSING:{ID_MAP[value]}")
        return record["id"]
    return value


def _assert_no_local_ids(value: Any) -> None:
    if isinstance(value, dict):
        for item in value.values():
            _assert_no_local_ids(item)
    elif isinstance(value, list):
        for item in value:
            _assert_no_local_ids(item)
    elif value in LOCAL_IDS:
        raise PlanError("LOCAL_ID_IN_QA_PLAN")


def generate(template: Path, inventory: Path, release_source: Path, expected_sha: str, output: Path) -> dict[str, Any]:
    if not SHA_RE.fullmatch(expected_sha):
        raise PlanError("EXPECTED_SHA_INVALID")
    source = _load(release_source)
    active_sha = source.get("active_sha")
    if not isinstance(active_sha, str) or not SHA_RE.fullmatch(active_sha):
        raise PlanError("RELEASE_SOURCE_SHA_INVALID")
    if active_sha.lower() != expected_sha.lower():
        raise PlanError("RELEASE_SHA_MISMATCH")
    inv = _inventory(inventory)
    plan = copy.deepcopy(_load(template))
    selection = plan.get("selection", {})
    requests = plan.get("requests")
    cleanup = plan.get("cleanup")
    if not isinstance(requests, list) or len(requests) != 22 or not isinstance(cleanup, list) or len(cleanup) != 2:
        raise PlanError("PLAN_SHAPE_INVALID")
    plan = _replace_ids(plan, inv["resources"])
    plan["id_source"] = "runtime-inventory"
    plan["qa_cleanup_policy"] = "Resolve every DELETE from the active-window inventory; verify namespace, ownership and purpose immediately before deletion; omit a resource that was not created."
    plan["template_sha"] = source.get("template_sha")
    plan["qa_guards"] = {
        "target_env": "staging",
        "expected_release_sha": expected_sha,
        "qa_writes_authorized": "1",
        "leo_negative_writes_authorized": "1",
        "https_verified": True,
    }
    plan["inventory"] = {"namespace": NAMESPACE, "source": inventory.name}
    plan["inventory_records"] = {
        key: {"purpose": record["purpose"], "owner": record["owner"], "created": record.get("created", True)}
        for key, record in inv["resources"].items()
    }
    # Cleanup ids are resolved at runtime.  No DELETE may use a template id.
    plan["cleanup"] = [
        {
            **plan["cleanup"][0],
            "path": "/cart/items/{{B06b.data.id}}",
            "inventory_ref": "B06b",
            "source_case": "B06b",
        },
        {
            **plan["cleanup"][1],
            "path": "/cart/items/{{B20.data.id}}",
            "inventory_ref": "B20",
            "source_case": "B20",
        },
    ]
    for case in plan["requests"]:
        if case.get("id") == "B21":
            case["inventory_ref"] = "B21"
    _assert_no_local_ids(plan)
    if output.exists() or output.is_symlink():
        raise PlanError("OUTPUT_EXISTS")
    output.write_text(json.dumps(plan, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")
    return {"status": "PASS", "requests": len(requests), "cleanup": len(cleanup), "sha": expected_sha, "namespace": NAMESPACE}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("template", type=Path)
    parser.add_argument("inventory", type=Path)
    parser.add_argument("release_source", type=Path)
    parser.add_argument("--expected-sha", required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    try:
        print(json.dumps(generate(args.template, args.inventory, args.release_source, args.expected_sha, args.output), sort_keys=True))
        return 0
    except (OSError, ValueError, json.JSONDecodeError) as exc:
        print(f"BLOCKED: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
