#!/usr/bin/env bash
# Staging-only Easebuzz reconciliation. Install a system cron entry only after approval.
set -euo pipefail
stage=/home/bgt9xah2o3eo/staging-astrowallclocks
private=/home/bgt9xah2o3eo/.astro-stage-deploy
test "$(/usr/bin/wp --path="$stage" config get DB_NAME --type=constant 2>/dev/null)" = bgt9xah2o3eo_awstage
test "$(/usr/bin/wp --path="$stage" option get siteurl 2>/dev/null)" = https://staging.astrowallclocks.com
/usr/bin/flock -n "$private/recovery.lock" \
    /usr/bin/wp --path="$stage" cron event run astro_easebuzz_recover_order --due-now --quiet
