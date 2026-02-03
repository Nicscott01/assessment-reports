#!/usr/bin/env bash
set -euo pipefail

# Run PHP lint on all plugin PHP files, excluding vendor.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}/.."

find . -name '*.php' ! -path './vendor/*' -print0 | xargs -0 -n1 php -l

echo "PHP lint passed."
