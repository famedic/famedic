#!/usr/bin/env python3
"""Validate RC1 OpenAPI and Postman artifacts."""
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OPENAPI = ROOT / "docs/Akubica/akubica-openapi-v1.3.0-rc1.yaml"
POSTMAN = ROOT / "postman/Famedic-Akubica-v1.3.0-rc1.postman_collection.json"
POSTMAN_MIRROR = ROOT / "docs/Akubica/v1.3.0-rc1/Famedic-Akubica-v1.3.0-rc1.postman_collection.json"
BASE_OPENAPI = ROOT / "docs/Akubica/akubica-openapi.yaml"

errors = []


def ok(msg: str) -> None:
    print(f"OK  {msg}")


def fail(msg: str) -> None:
    errors.append(msg)
    print(f"FAIL {msg}")


# YAML
try:
    import yaml  # type: ignore

    with OPENAPI.open(encoding="utf-8") as f:
        spec = yaml.safe_load(f)
    ok(f"YAML parse: {OPENAPI.name}")
except Exception as e:
    fail(f"YAML parse: {e}")
    spec = None

if spec:
    if spec.get("info", {}).get("version") != "1.3.0-rc1":
        fail("info.version != 1.3.0-rc1")
    else:
        ok("info.version 1.3.0-rc1")

    ids = re.findall(r"^\s+operationId:\s+(\S+)\s*$", OPENAPI.read_text(encoding="utf-8"), re.M)
    dup = {x for x in ids if ids.count(x) > 1}
    if dup:
        fail(f"operationId duplicados: {sorted(dup)}")
    else:
        ok(f"operationId únicos ({len(ids)})")

    base_ids = re.findall(
        r"^\s+operationId:\s+(\S+)\s*$", BASE_OPENAPI.read_text(encoding="utf-8"), re.M
    )
    if set(ids) != set(base_ids):
        only_new = set(ids) - set(base_ids)
        only_old = set(base_ids) - set(ids)
        if only_new or only_old:
            fail(f"operationId diff vs v1.2.3: +{only_new} -{only_old}")
        else:
            ok("operationId set igual a v1.2.3")
    else:
        ok("operationId set igual a v1.2.3")

    openapi_paths = set(spec.get("paths", {}).keys())

# Postman JSON
try:
    coll = json.loads(POSTMAN.read_text(encoding="utf-8"))
    ok(f"Postman JSON: {POSTMAN.name}")
except Exception as e:
    fail(f"Postman JSON: {e}")
    coll = None

postman_routes = set()


def walk(items, prefix=""):
    for it in items:
        if "item" in it:
            walk(it["item"], prefix)
        elif "request" in it:
            url = it["request"].get("url")
            if isinstance(url, str):
                path = url.replace("{{base_url}}", "").split("?")[0]
                method = it["request"]["method"].upper()
                postman_routes.add((method, path))


if coll and POSTMAN_MIRROR.exists():
    mirror = json.loads(POSTMAN_MIRROR.read_text(encoding="utf-8"))
    if mirror.get("info", {}).get("name") != coll.get("info", {}).get("name"):
        fail("Coleccion Postman espejo desincronizada (info.name)")
    else:
        ok("Postman espejo docs/Akubica/v1.3.0-rc1 presente")

if coll:
    walk(coll.get("item", []))
    ok(f"Postman rutas distintas: {len(postman_routes)}")

    if spec:
        missing_in_openapi = []
        for method, path in postman_routes:
            norm = path.replace("{{medical_cart_item_id}}", "{cart_item_id}")
            if norm not in openapi_paths:
                # allow path params
                alt = re.sub(r"/\{\{[^}]+\}\}", "/{cart_item_id}", path)
                alt = alt.replace("{{base_url}}", "")
                if alt not in openapi_paths and path not in openapi_paths:
                    missing_in_openapi.append((method, path))
        if missing_in_openapi:
            fail(f"Rutas Postman no en OpenAPI: {missing_in_openapi[:10]}")
        else:
            ok("Rutas Postman ⊆ paths OpenAPI (normalizado)")

if errors:
    print("\nResumen: FALLÓ", len(errors), "validación(es)")
    sys.exit(1)
print("\nResumen: todas las validaciones pasaron")
sys.exit(0)
