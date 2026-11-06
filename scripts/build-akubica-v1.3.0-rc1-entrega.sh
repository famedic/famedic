#!/usr/bin/env bash
# RC1 is incident evidence. Never overwrite/repackage its historical output.
set -euo pipefail
printf '%s\n' 'BLOCKED: RC1 builder retired. Use the RC1.1 check-only builder after review.' >&2
exit 1
