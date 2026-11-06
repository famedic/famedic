#!/usr/bin/env python3
"""Print only fixed categories; never matches, exception text, or input paths."""
import sys
from evidence_security import scan_main
if __name__ == '__main__':
    try:
        raise SystemExit(scan_main(sys.argv[1:]))
    except Exception:
        print('{"result":"FAIL","categories":["SCAN_ERROR"]}')
        raise SystemExit(1)
