#!/usr/bin/env python3
"""Retain only synthetic assertion IDs, outcomes and durations from JUnit."""
import os
import sys
from pathlib import Path
from evidence_security import Scanner, project_junit, read_limited

def main():
    try:
        if len(sys.argv) != 3:
            raise ValueError()
        data = project_junit(read_limited(sys.argv[1]))
        scanner = Scanner(); scanner.blob(data, '.xml')
        if scanner.findings:
            raise ValueError()
        os.umask(0o077)
        with Path(sys.argv[2]).open('xb') as handle:
            handle.write(data)
        print('PASS: projected JUnit; original input preserved')
        return 0
    except Exception:
        print('FAIL: JUnit projection blocked; input values omitted', file=sys.stderr)
        return 1

if __name__ == '__main__':
    raise SystemExit(main())
