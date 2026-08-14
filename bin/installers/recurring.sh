#!/usr/bin/env bash

set -eo pipefail

GITHUB_TOKEN="$1"
WP_CORE_DIR="$2"
BRANCH="${3:-master}"

if [[ -z "${GITHUB_TOKEN}" ]] || [[ -z "${WP_CORE_DIR}" ]]; then
    echo "Usage: $0 <github_token> <wp_core_dir> [branch]"
    exit 1
fi

source "$(dirname "$0")/../lib/github.sh"

echo "Installing EDD Recurring (${BRANCH})"
github_download "${GITHUB_TOKEN}" \
    "https://github.com/awesomemotive/edd-recurring/archive/${BRANCH}.zip" \
    /tmp/recurring.zip

unzip -qq /tmp/recurring.zip -d "${WP_CORE_DIR}/wp-content/plugins/"

RECURRING_DIR=$(find "${WP_CORE_DIR}/wp-content/plugins/" -maxdepth 1 -iname "*recurring*" -type d | head -1)

if [[ -n "${RECURRING_DIR}" ]]; then
    mv "${RECURRING_DIR}" "${WP_CORE_DIR}/wp-content/plugins/edd-recurring"
else
    echo "Error: Could not find the extracted EDD Recurring directory."
    echo "Contents of plugins directory:"
    ls -la "${WP_CORE_DIR}/wp-content/plugins/"
    exit 1
fi
