#!/usr/bin/env bash
# Do not run A+B with an OTP inherited from a previous challenge.
set -euo pipefail
printf '%s\n' 'BLOCKED: run phase-a then phase-b separately after operational authorization.' >&2
exit 1
