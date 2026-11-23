#!/usr/bin/env python3
"""Obtain a sanitized release identity from the deployed Laravel helper."""
from __future__ import annotations

import argparse
import json
import shlex
import subprocess
from pathlib import Path


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--inventory-command", required=True,
                        help="Laravel inventory command, local or private-channel bridge")
    parser.add_argument("--expected-sha", required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()

    result = subprocess.run(
        args.inventory_command if isinstance(args.inventory_command, list) else shlex.split(args.inventory_command),
        input=json.dumps({"action": "identity"}) + "\n",
        text=True,
        capture_output=True,
        check=False,
    )
    try:
        payload = json.loads(result.stdout.strip())
    except json.JSONDecodeError:
        payload = {}
    sha = payload.get("active_sha")
    if result.returncode != 0 or payload.get("status") != "verified" or not isinstance(sha, str):
        print(json.dumps({"status": "BLOCKED", "code": "RELEASE_SOURCE_UNVERIFIED"}))
        return 1
    if sha.lower() != args.expected_sha.lower():
        print(json.dumps({"status": "BLOCKED", "code": "RELEASE_SHA_MISMATCH"}))
        return 1
    sanitized = {"active_sha": sha, "release": payload.get("release"), "source": "laravel-inventory-helper"}
    args.output.write_text(json.dumps(sanitized, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"status": "PASS", "active_sha": sha, "source": sanitized["source"]}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
