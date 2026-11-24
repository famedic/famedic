#!/usr/bin/env python3
"""Read-only inventory verifier for functional-window-2 cleanup.

The helper derives release identity from Git and resource provenance from a
SQLite database. It accepts no resource mutation operation and never handles
credentials.
"""
from __future__ import annotations

import json
import os
import sqlite3
import subprocess
import sys


NAMESPACE = "akubica-uat-batch2-a4-v1"


def active_sha() -> str:
    root = os.environ.get("FW2_RELEASE_ROOT")
    if not root:
        raise RuntimeError("RELEASE_ROOT_REQUIRED")
    try:
        return subprocess.run(["git", "-C", root, "rev-parse", "HEAD"], capture_output=True, text=True, check=True, timeout=5).stdout.strip()
    except subprocess.CalledProcessError:
        try:
            windows_root = subprocess.run(["wslpath", "-w", root], capture_output=True, text=True, check=True, timeout=5).stdout.strip()
            return subprocess.run(["git.exe", "-C", windows_root, "rev-parse", "HEAD"], capture_output=True, text=True, check=True, timeout=5).stdout.strip()
        except (OSError, subprocess.SubprocessError):
            raise RuntimeError("RELEASE_SOURCE_UNAVAILABLE") from None
    except (OSError, subprocess.SubprocessError):
        raise RuntimeError("RELEASE_SOURCE_UNAVAILABLE") from None


def verify(request: dict) -> dict:
    if request.get("action") != "verify":
        raise RuntimeError("ACTION_NOT_ALLOWED")
    if request.get("namespace") != NAMESPACE:
        raise RuntimeError("NAMESPACE_NOT_ALLOWED")
    expected_sha = request.get("expected_sha")
    if not isinstance(expected_sha, str) or active_sha().lower() != expected_sha.lower():
        raise RuntimeError("RELEASE_SHA_MISMATCH")
    db_path = os.environ.get("FW2_INVENTORY_DB")
    if not db_path:
        raise RuntimeError("INVENTORY_DB_REQUIRED")
    with sqlite3.connect(db_path) as db:
        row = db.execute(
            "SELECT resource_id, namespace, owner, purpose, created "
            "FROM window2_inventory WHERE namespace = ? AND resource_id = ?",
            (NAMESPACE, int(request.get("resource_id", 0))),
        ).fetchone()
    if row is None or not row[4] or row[2] != request.get("owner") or row[3] != request.get("purpose"):
        raise RuntimeError("INVENTORY_OWNERSHIP_UNCONFIRMED")
    return {"status": "verified", "namespace": row[1], "resource_id": row[0], "purpose": row[3], "owner": row[2]}


def main() -> int:
    try:
        request = json.loads(sys.stdin.read())
        if not isinstance(request, dict):
            raise RuntimeError("REQUEST_INVALID")
        print(json.dumps(verify(request), separators=(",", ":")))
        return 0
    except (OSError, ValueError, RuntimeError, sqlite3.Error):
        print(json.dumps({"error": "INVENTORY_VERIFICATION_FAILED"}, separators=(",", ":")))
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
