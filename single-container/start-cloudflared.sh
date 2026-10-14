#!/bin/bash
set -euo pipefail

if [[ -z "${CLOUDFLARE_TUNNEL_TOKEN:-}" ]]; then
    echo 'Cloudflare Tunnel is disabled: set CLOUDFLARE_TUNNEL_TOKEN to enable it.'
    exit 0
fi

exec cloudflared tunnel --no-autoupdate run --token "$CLOUDFLARE_TUNNEL_TOKEN"