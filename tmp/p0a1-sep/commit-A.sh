#!/bin/bash
set -euo pipefail
cd /home/laliyo/projects/Odessa-Famedic/famedic
git commit --trailer "Co-authored-by: Cursor <cursoragent@cursor.com>" -m "$(cat <<'EOF'
test(api): isolate bearer tokens between API actors

EOF
)"
git log -1 --oneline