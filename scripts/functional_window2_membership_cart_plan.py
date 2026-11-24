#!/usr/bin/env python3
"""Generate the membership-cart operational plan from verified release identity."""
from __future__ import annotations

import argparse
import copy
import json
import re
import uuid
from pathlib import Path
from typing import Any

SHA_RE = re.compile(r"^[0-9a-f]{40}$", re.I)
WINDOW_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{2,48}$")
KEY_RE = re.compile(r"^[A-Za-z0-9._-]{8,128}$")


class PlanError(ValueError):
    pass


def _load(path: Path) -> dict[str, Any]:
    value = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise PlanError("JSON_OBJECT_REQUIRED")
    return value


def _replace(value: Any, replacements: dict[str, str]) -> Any:
    if isinstance(value, dict):
        return {key: _replace(item, replacements) for key, item in value.items()}
    if isinstance(value, list):
        return [_replace(item, replacements) for item in value]
    if isinstance(value, str):
        for marker, replacement in replacements.items():
            value = value.replace(marker, replacement)
        return value
    return value


def generate(template: Path, release_source: Path, output: Path, window_id: str) -> dict[str, Any]:
    if not WINDOW_RE.fullmatch(window_id):
        raise PlanError("WINDOW_ID_INVALID")
    source = _load(release_source)
    if source.get("status") not in {"verified", "PASS"}:
        raise PlanError("RELEASE_SOURCE_UNVERIFIED")
    sha = source.get("active_sha")
    if not isinstance(sha, str) or not SHA_RE.fullmatch(sha):
        raise PlanError("RELEASE_SOURCE_SHA_INVALID")
    plan = _load(template)
    if plan.get("template") is not True or "{{RELEASE_SHA_FROM_IDENTITY}}" not in json.dumps(plan):
        raise PlanError("TEMPLATE_NOT_VERSIONED")

    suffix = uuid.uuid4().hex[:12]
    b12_key = f"it0061-{window_id}-b12-{suffix}"
    b13_key = f"it0061-{window_id}-b13-{suffix}"
    if not KEY_RE.fullmatch(b12_key) or not KEY_RE.fullmatch(b13_key) or b12_key == b13_key:
        raise PlanError("IDEMPOTENCY_KEY_INVALID")
    generated = _replace(plan, {
        "{{RELEASE_SHA_FROM_IDENTITY}}": sha,
        "{{B12_IDEMPOTENCY_KEY}}": b12_key,
        "{{B13_IDEMPOTENCY_KEY}}": b13_key,
    })
    generated["template"] = False
    generated["generated_from_release_source"] = release_source.name
    generated["window_id"] = window_id
    generated["qa_guards"]["expected_release_sha"] = sha
    generated["idempotency"] = {"B12": b12_key, "B13": b13_key, "B17_reuses": "B12"}
    if output.exists() or output.is_symlink():
        raise PlanError("OUTPUT_EXISTS")
    output.write_text(json.dumps(generated, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")
    return {"status": "PASS", "sha": sha, "window_id": window_id, "b12_key": b12_key, "b13_key": b13_key}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("template", type=Path)
    parser.add_argument("release_source", type=Path)
    parser.add_argument("--window-id", required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    try:
        print(json.dumps(generate(args.template, args.release_source, args.output, args.window_id), sort_keys=True))
        return 0
    except (OSError, ValueError, json.JSONDecodeError) as exc:
        print(f"BLOCKED: {exc}")
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
