#!/usr/bin/env bash

# Download a file from a GitHub repository using a personal access token.
# Exits with a clear error if the download fails rather than continuing silently.
# Usage: github_download <token> <url> <dest>
github_download() {
    local token="$1"
    local url="$2"
    local dest="$3"

    curl -fsSL -u "x-access-token:${token}" -o "${dest}" "${url}" || {
        echo "Error: Failed to download ${url}." >&2
        echo "Check that the URL and branch name are correct and that your token has repo access." >&2
        exit 1
    }
}
