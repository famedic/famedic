#!/usr/bin/env python3
"""Derive sanitized release and inventory artifacts from verifiable sources."""
from __future__ import annotations

import argparse
import json
import sqlite3
import subprocess
from pathlib import Path


NAMESPACE = "akubica-uat-batch2-a4-v1"


def release_source(root: Path) -> dict:
    try:
        sha = subprocess.run(["git", "-C", str(root), "rev-parse", "HEAD"], capture_output=True, text=True, check=True).stdout.strip()
    except subprocess.CalledProcessError:
        windows_root = subprocess.run(["wslpath", "-w", str(root)], capture_output=True, text=True, check=True).stdout.strip()
        sha = subprocess.run(["git.exe", "-C", windows_root, "rev-parse", "HEAD"], capture_output=True, text=True, check=True).stdout.strip()
    return {"active_sha": sha, "source": "git-rev-parse", "root": str(root)}


def inventory(db_path: Path) -> dict:
    with sqlite3.connect(db_path) as db:
        rows = db.execute(
            "SELECT resource_key, resource_id, namespace, owner, purpose, created "
            "FROM window2_inventory WHERE namespace = ? ORDER BY resource_key",
            (NAMESPACE,),
        ).fetchall()
    resources = {
        key: {"id": resource_id, "namespace": namespace, "owner": owner, "purpose": purpose, "created": bool(created)}
        for key, resource_id, namespace, owner, purpose, created in rows
    }
    if not resources:
        raise ValueError("INVENTORY_EMPTY")
    return {"namespace": NAMESPACE, "source": "sqlite-query", "resources": resources}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--release-root", type=Path, required=True)
    parser.add_argument("--db", type=Path, required=True)
    parser.add_argument("--release-output", type=Path, required=True)
    parser.add_argument("--inventory-output", type=Path, required=True)
    args = parser.parse_args()
    args.release_output.write_text(json.dumps(release_source(args.release_root), indent=2) + "\n", encoding="utf-8")
    args.inventory_output.write_text(json.dumps(inventory(args.db), indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"status": "PASS", "namespace": NAMESPACE, "source": "git+sqlite"}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
