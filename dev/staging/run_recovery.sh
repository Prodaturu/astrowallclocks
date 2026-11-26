#!/usr/bin/env bash
# Staging-only Easebuzz reconciliation. Install a system cron entry only after approval.
set -euo pipefail
umask 077
stage=/home/bgt9xah2o3eo/staging-astrowallclocks
private=/home/bgt9xah2o3eo/.astro-stage-deploy
health="$private/recovery-health.log"
log_status() { printf '%s %s\n' "$(/usr/bin/date -u +%FT%TZ)" "$1" >> "$health"; }
if [ "$(/usr/bin/wp --path="$stage" config get DB_NAME --type=constant 2>/dev/null)" != bgt9xah2o3eo_awstage ] \
    || [ "$(/usr/bin/wp --path="$stage" option get siteurl 2>/dev/null)" != https://staging.astrowallclocks.com ]; then
    log_status preflight_failed
    exit 2
fi
if /usr/bin/flock -n "$private/recovery.lock" \
    /usr/bin/wp --path="$stage" cron event run astro_easebuzz_recover_order --due-now --quiet >/dev/null 2>&1; then
    log_status ok
else
    log_status failed_or_locked
    exit 1
fi
