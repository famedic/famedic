#!/usr/bin/env python3
import re
import sys
from pathlib import Path

raw = Path(sys.argv[1]).read_text(encoding="utf-8")
# Remove long hex/base64-like tokens from failure messages
san = re.sub(r"\b[A-Za-z0-9+/=_\-]{32,}\b", "[REDACTED]", raw)
Path(sys.argv[2]).write_text(san, encoding="utf-8")
