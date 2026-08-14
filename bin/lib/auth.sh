#!/usr/bin/env bash

# Extract the GitHub token from a COMPOSER_AUTH JSON string.
# Uses python3 when available, falls back to grep/sed for environments where it is not.
extract_github_token() {
    local auth_json="$1"
    if command -v python3 &>/dev/null; then
        python3 -c "
import json, sys
try:
    data = json.loads(sys.stdin.read())
    print(data.get('github-oauth', {}).get('github.com', ''))
except Exception:
    print('')
" <<< "${auth_json}"
    else
        echo "${auth_json}" | grep -o '"github.com":"[^"]*' | sed 's/"github.com":"//g'
    fi
}

# Resolve a GitHub token for authenticated downloads, then validate its format.
# Source precedence: an explicit COMPOSER_AUTH wins (so CI secrets are honored),
# then the GitHub CLI (gh auth token), then Composer's global auth.json. Exits
# early with a clear error if auth cannot be resolved or the token looks invalid,
# rather than letting Docker fail silently later.
resolve_and_validate_auth() {
    local context="$1"
    local token=""

    if [[ -n "${COMPOSER_AUTH}" ]]; then
        # An explicitly provided COMPOSER_AUTH always wins; leave it untouched.
        token=$(extract_github_token "${COMPOSER_AUTH}")
    else
        # Default to the GitHub CLI, which is how the team authenticates.
        if command -v gh &>/dev/null; then
            token=$(gh auth token 2>/dev/null)
            [[ -n "${token}" ]] && echo "Note: Using GitHub token from the gh CLI."
        fi

        # Fall back to Composer's global auth.json.
        if [[ -z "${token}" ]]; then
            local composer_home="${COMPOSER_HOME:-$([[ $(uname) == 'Darwin' ]] && echo "$HOME/.composer" || echo "$HOME/.config/composer")}"
            local auth_file="${composer_home}/auth.json"
            if [[ -f "${auth_file}" ]]; then
                token=$(extract_github_token "$(cat "${auth_file}")")
                [[ -n "${token}" ]] && echo "Note: Using GitHub token from ${auth_file}"
            fi
        fi

        # Build COMPOSER_AUTH from the resolved token for Composer and the container.
        if [[ -n "${token}" ]]; then
            export COMPOSER_AUTH
            COMPOSER_AUTH="{\"github-oauth\":{\"github.com\":\"${token}\"}}"
        fi
    fi

    if [[ -z "${token}" ]]; then
        echo "Error: A GitHub token is required for ${context} but could not be resolved." >&2
        echo "Authenticate with: gh auth login (or set COMPOSER_AUTH / composer config --global github-oauth.github.com <your_token>)." >&2
        exit 1
    fi

    if [[ ! "${token}" =~ ^(gh[a-z]_|github_pat_)[A-Za-z0-9_]+ ]]; then
        echo "Error: GitHub token does not look valid (expected gh..._ or github_pat_...)." >&2
        echo "Refresh it with: gh auth login (or composer config --global github-oauth.github.com <your_token>)." >&2
        exit 1
    fi
}
