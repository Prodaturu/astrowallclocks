#!/usr/bin/env bash
# Create/remove a short-lived Basic-auth identity for local browser QA.
set -euo pipefail
stage=/home/bgt9xah2o3eo/staging-astrowallclocks
auth=/home/bgt9xah2o3eo/.astro-staging-auth
test "$(/usr/bin/wp --path="$stage" config get DB_NAME --type=constant 2>/dev/null)" = bgt9xah2o3eo_awstage
case "${1:-}" in
  add)
    user="astro_visual_$(date +%s)"
    pass=$(openssl rand -hex 20)
    hash=$(printf '%s\n' "$pass" | openssl passwd -apr1 -stdin)
    printf '%s:%s\n' "$user" "$hash" >> "$auth"
    printf '%s:%s\n' "$user" "$pass"
    ;;
  remove)
    user="${2:-}"
    [[ "$user" =~ ^astro_visual_[0-9]+$ ]]
    work=$(mktemp)
    chmod 600 "$work"
    grep -v "^${user}:" "$auth" > "$work" || true
    cat "$work" > "$auth"
    rm -f "$work"
    ;;
  *) exit 2 ;;
esac
