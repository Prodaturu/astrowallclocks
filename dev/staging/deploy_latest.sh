#!/usr/bin/env bash
# Historical staging snapshot launcher. Its pinned archives predate the release candidate.
set -euo pipefail
echo 'Refusing obsolete staging snapshot. Use the verified release candidate in dev/release/README.md.' >&2
exit 2
