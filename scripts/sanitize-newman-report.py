#!/usr/bin/env python3
"""Project Newman evidence using an explicit allowlist; never copy raw data."""
import json
import os
import sys
from pathlib import Path
from evidence_security import Scanner, project_newman, read_limited, strict_json

def main():
    try:
        if len(sys.argv) not in (4, 5):
            raise ValueError()
        import yaml
        contract = Path(sys.argv[4]) if len(sys.argv) == 5 else Path(__file__).resolve().parents[1] / 'docs/Akubica/akubica-openapi-v1.3.0-rc1.yaml'
        spec = yaml.safe_load(read_limited(contract).decode('utf-8'))
        projected = project_newman(strict_json(read_limited(sys.argv[1]).decode('utf-8')), spec)
        encoded = (json.dumps(projected, indent=2, allow_nan=False) + chr(10)).encode()
        scanner = Scanner(); scanner.blob(encoded, '.json')
        if scanner.findings:
            raise ValueError()
        targets = [Path(sys.argv[2]), Path(sys.argv[3])]
        if len(set(p.resolve() for p in targets)) != 2 or any(p.exists() or p.is_symlink() for p in targets):
            raise ValueError()
        os.umask(0o077)
        for target in targets:
            with target.open('xb') as handle:
                handle.write(encoded)
        print('PASS: projected Newman evidence; original input preserved')
        return 0
    except Exception:
        print('FAIL: evidence projection blocked; input values omitted', file=sys.stderr)
        return 1

if __name__ == '__main__':
    raise SystemExit(main())
