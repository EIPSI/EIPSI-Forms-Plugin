#!/usr/bin/env bash
# No set -x. Bearer goes via stdin config, never a curl command-line argument.
set -euo pipefail
: "${EIPSI_API_BASE:?Set the API base URL}"
: "${EIPSI_API_KEY:?Set the API key privately}"
[[ "$EIPSI_API_KEY" =~ ^eipsi_[a-f0-9]{32}_[a-f0-9]{64}$ ]] || exit 2
[[ "$EIPSI_API_BASE" == https://* || "$EIPSI_API_BASE" == http://127.0.0.1:* || "$EIPSI_API_BASE" == http://127.0.0.1/* || "$EIPSI_API_BASE" == http://localhost/* || "$EIPSI_API_BASE" == http://localhost:* ]] || exit 2
route="${1:-/status}"
[[ "$route" == /* && "$route" != *$'\n'* && "$route" != *'"'* ]] || exit 2
# Pass flags after the route, e.g. --data '{"format":"csv"}' or --range 0-127.
shift || true
printf 'header = "Authorization: Bearer %s"\n' "$EIPSI_API_KEY" |
  curl --config - --silent --show-error --fail-with-body --max-time 90 \
    --header 'Content-Type: application/json' "${EIPSI_API_BASE%/}${route}" "$@"
