#!/usr/bin/env bash

set -eo pipefail

WP_CORE_DIR="$1"

if [[ -z "${WP_CORE_DIR}" ]]; then
    echo "Usage: $0 <wp_core_dir>"
    exit 1
fi

PLUGIN_DIR="${WP_CORE_DIR}/wp-content/plugins/elementor"

# Elementor is public on wp.org, so no token/auth is required. Fetch the latest
# release zip and unzip it into the plugins directory. Idempotent: an existing
# install is removed first so we always end up with the latest.
if [[ -d "${PLUGIN_DIR}" ]]; then
    echo "Removing existing Elementor install at ${PLUGIN_DIR}"
    rm -rf "${PLUGIN_DIR}"
fi

echo "Installing Elementor (latest)"
curl -fsSL "https://downloads.wordpress.org/plugin/elementor.zip" -o /tmp/elementor.zip || {
    echo "Error: Failed to download Elementor from wp.org." >&2
    exit 1
}

unzip -qq /tmp/elementor.zip -d "${WP_CORE_DIR}/wp-content/plugins/"

if [[ -f "${PLUGIN_DIR}/elementor.php" ]]; then
    echo "✔ Elementor installed at ${PLUGIN_DIR}"
else
    echo "Error: Could not find the extracted Elementor main file (${PLUGIN_DIR}/elementor.php)."
    echo "Contents of plugins directory:"
    ls -la "${WP_CORE_DIR}/wp-content/plugins/"
    exit 1
fi
