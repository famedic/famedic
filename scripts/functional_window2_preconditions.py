#!/usr/bin/env python3
"""Validate a synthetic, namespace-isolated preparation plan offline."""
from __future__ import annotations

import json
from pathlib import Path
from typing import Any


class PreconditionsError(ValueError):
    pass


def validate(path: Path) -> dict[str, Any]:
    data = json.loads(path.read_text(encoding="utf-8"))
    if data.get("legacy_namespace") == data.get("namespace") or data.get("external_effects") is not False:
        raise PreconditionsError("ISOLATION_INVALID")
    resources = data.get("resources")
    if not isinstance(resources, list) or not resources:
        raise PreconditionsError("RESOURCES_MISSING")
    aliases: set[str] = set()
    for resource in resources:
        if not isinstance(resource, dict):
            raise PreconditionsError("RESOURCE_INVALID")
        alias = resource.get("alias")
        caps = resource.get("capabilities")
        if not isinstance(alias, str) or alias in aliases or "*" in alias:
            raise PreconditionsError("ALIAS_COLLISION")
        if not isinstance(caps, list) or not caps or any(not isinstance(c, str) or c == "*" for c in caps):
            raise PreconditionsError("CAPABILITIES_NOT_LITERAL")
        if resource.get("profile") not in {"native", "leo"}:
            raise PreconditionsError("PROFILE_INVALID")
        if not all(isinstance(resource.get(k), str) and resource[k] for k in ("delegation", "binding", "ownership")):
            raise PreconditionsError("RELATION_MISSING")
        aliases.add(alias)
    return {"namespace": data["namespace"], "resources": len(resources), "external_effects": False}


if __name__ == "__main__":
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("manifest", type=Path)
    args = parser.parse_args()
    print(json.dumps(validate(args.manifest), sort_keys=True))
