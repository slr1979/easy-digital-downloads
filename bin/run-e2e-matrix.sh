#!/bin/bash

set -eo pipefail

# Prevents Git-Bash/MSYS on Windows from mangling absolute paths passed to Docker; has no effect on macOS/Linux.
export MSYS_NO_PATHCONV=1

# Single source for WP-latest resolution: the Node resolver (also used by
# e2e/playwright.config.js). Node is always available here — the matrix loop
# runs `npx playwright`. resolve_wp_latest prints the current stable major.minor
# to stdout, or nothing on failure (so the caller's fallback fires).
REPO_E2E_RESOLVER="$(cd "$(dirname "$0")/.." && pwd)/e2e/global/resolve-wp-latest.js"
resolve_wp_latest() { node "$REPO_E2E_RESOLVER" 2>/dev/null; }

# Single source for the executed-test count (portable awk, sourced with no side effects).
# shellcheck source=bin/count-executed-tests.sh
. "$(cd "$(dirname "$0")" && pwd)/count-executed-tests.sh"

# Single source for the Docker-daemon-running check, sourced with no side effects.
# shellcheck source=bin/ensure-docker-running.sh
. "$(cd "$(dirname "$0")" && pwd)/ensure-docker-running.sh"

show_help() {
  printf -- 'Usage: %s [OPTIONS]\n\n' "$0";

  echo "Run the E2E compatibility matrix against multiple WP/PHP/Elementor version combinations."
  echo "With no options, runs the default matrix list (full)."
  echo "For single-cell runs, use: npm run test:e2e"
  echo ""
  echo "Matrix Options:";
  printf -- '    --matrix[=LIST]\tRun all cells in a matrix list from e2e/matrix.json.\n';
  printf -- '\t\t\tLIST: full (default), compat.\n';
  printf -- '\t\t\tRequires: jq, Docker, >=10GB free disk.\n';
  printf -- '    --full\t\tOverride matrix scope to run ALL tests per cell (ignores scope in matrix.json).\n';
  printf -- '    --retry\t\tRetry failed matrix cells once after the initial pass.\n';
  echo "";
  echo "Docker Environment Options:";
  printf -- '-d, --docker\t\tExplicitly assert Docker mode (implied by --matrix).\n';
  printf -- '-P, --port PORT\t\tHTTP port for Docker containers (Default: 8081).\n';
  echo "";
  printf -- '-h, --help\t\tShow this help.\n';
  echo "";
  echo "Matrix list guide:";
  echo "  compat  6-cell set (scope: compat) — each cell runs @compat specs only.";
  echo "          Covers WP 6.8/latest x Elementor 3.35.9/latest, plus one latest/none cell (PHP 8.2 throughout).";
  echo "  full    Same 6-cell set (scope: all) — each cell runs the whole suite. This is the default list.";
  echo "  edd:lite cells run @lite-scoped specs only (never the Pro suite), on a real Lite boot.";
  echo "  EDD_E2E_LITE_MODE: empty/unset = off; ANY non-empty value = on.";
  echo "  A cell that executes 0 tests is reported FAIL (not PASS) by the zero-test guard.";
  echo "";
  echo "Examples:";
  echo "  $0                                 # Default list (full, all tests)";
  echo "  $0 --matrix                        # Same as above, explicit --matrix";
  echo "  $0 --matrix=full                   # Same as above, explicit list name";
  echo "  $0 --matrix=compat                 # 6-cell compat list, @compat tests only";
  echo "  $0 --matrix=compat --full          # compat list, ALL tests per cell";
  echo "  $0 --matrix --retry                # Default list with auto-retry on failures";
  echo "";
  echo "For single-cell runs:";
  echo "  npm run test:e2e                           # default Docker cell";
  echo "  npm run test:e2e:headed                    # with visible browser";
  echo "  npm run test:e2e:debug                     # debug mode";
  echo "  npx playwright test --project=desktop      # specific project";
  echo "  npx playwright test --workers=1            # sequential";
  echo "  docker compose -f docker-compose.e2e.yml stop    # stop containers";
  echo "  docker compose -f docker-compose.e2e.yml down -v # remove containers + volumes";
}

# Initialize variables
USE_DOCKER=false
MATRIX_MODE=false
MATRIX_LIST=""
MATRIX_GIVEN=false
MATRIX_FULL=false
MATRIX_RETRY=false

# Portable manual argument parsing (POSIX/bash, no GNU getopt — works on macOS BSD too).
# Supports long `--opt value` and `--opt=value`, short `-x value`, and `-h/--help`.
# `--matrix` is an optional-argument flag: bare `--matrix` uses the default list,
# `--matrix=LIST` selects a named list (a following bare word is NOT consumed as its value).
# require_arg ensures a value-taking flag was actually given an argument.
require_arg() {
  # $1 = flag name, $2 = candidate value (passed as "${2-}", so empty when missing).
  case "${2-}" in
    '' | -* )
      echo "Error: option '$1' requires an argument." >&2
      show_help
      exit 1
      ;;
  esac
}

while [ $# -gt 0 ]; do
  case "$1" in
    --docker|-d )
        USE_DOCKER=true
        shift
        ;;
    --port=*|-P=* )
        export WP_PORT="${1#*=}"
        shift
        ;;
    --port|-P )
        require_arg "$1" "${2-}"
        export WP_PORT="$2"
        shift 2
        ;;
    --full )
        MATRIX_FULL=true
        shift
        ;;
    --retry )
        MATRIX_RETRY=true
        shift
        ;;
    --matrix=* )
        # Optional-argument flag: --matrix=LIST selects a named list.
        MATRIX_MODE=true
        MATRIX_GIVEN=true
        MATRIX_LIST="${1#*=}"
        shift
        ;;
    --matrix )
        # Bare --matrix uses the default list; a following bare word is NOT consumed.
        MATRIX_MODE=true
        MATRIX_GIVEN=true
        shift
        ;;
    --help|-h )
        show_help
        exit 0
        ;;
    --)
        shift
        break
        ;;
    -p|--php|--php=*|--wp|--wp=*|--elementor|--elementor=*|-f|--filter|--filter=*|--compat )
        echo "Error: '$1' is no longer supported; this runner is matrix-only. For single-cell runs use: npm run test:e2e" >&2
        exit 1
        ;;
    -*)
        echo "Error: unknown option '$1'." >&2
        show_help
        exit 1
        ;;
    *)
        echo "Error: unexpected argument '$1'." >&2
        show_help
        exit 1
        ;;
  esac
done

# Load the root .env file (if it exists) so this runner picks up the same
# variable overrides that Docker Compose auto-loads.  Without this, Docker
# Compose resolves e.g. WP_ADMIN_PASS from .env while Playwright falls
# back to the hardcoded default — causing an auth mismatch.
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_ENV="${SCRIPT_DIR}/../.env"
if [ -f "$ROOT_ENV" ]; then
    set -a
    # shellcheck source=/dev/null
    . "$ROOT_ENV"
    set +a
fi

# Set defaults for Docker-specific options (always needed).
# WP_VERSION uses the no-colon form `${VAR-default}` so that an unset variable gets the
# latest default. The matrix loop overrides WP_VERSION per-cell.
export TEST_PHP_VERSION="${TEST_PHP_VERSION:-8.2}"
export WP_VERSION="${WP_VERSION-latest}"
export ELEMENTOR_VERSION="${ELEMENTOR_VERSION:-latest}"
export WP_PORT="${WP_PORT:-8081}"

# Pick a WP-CLI Docker tag compatible with the requested PHP version.
# cli-2.12 only ships PHP 8.1+; PHP 8.0 needs the older cli-2-php8.0 tag.
if [ -z "$WP_CLI_TAG" ]; then
    case "$TEST_PHP_VERSION" in
        8.0) export WP_CLI_TAG="cli-2-php8.0" ;;
        *)   export WP_CLI_TAG="cli-2.12-php${TEST_PHP_VERSION}" ;;
    esac
fi
export WP_TITLE="${WP_TITLE:-EDD E2E}"
export WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL:-admin@example.com}"

# Use a consistent project name for Docker
export COMPOSE_PROJECT_NAME="edd-e2e-matrix"

cd "$(dirname "$0")/.."

# Default (no --matrix flag): run the default matrix list. Mirrors run-phpunit-matrix.sh —
# a bare invocation runs the default matrix (single-cell runs use `npm run test:e2e`).
# Flipped here, BEFORE the preflight block, so a bare run still hits the
# jq/disk/USE_DOCKER/credential preflight below.
if [ "$MATRIX_MODE" = false ]; then
    MATRIX_MODE=true
fi

# Matrix preflight checks — always run (matrix mode is the only mode).
if [ "$MATRIX_MODE" = true ]; then
    command -v jq >/dev/null 2>&1 || { echo "Error: jq is required. Install with: brew install jq (macOS), apt-get install jq (Debian/Ubuntu), choco install jq (Windows), or see https://jqlang.github.io/jq/download/"; exit 2; }

    # Disk-space preflight — a matrix run pulls ~3-4 GB of Docker images + volumes.
    # Use POSIX -P form (cross-platform: macOS BSD df + Linux GNU df both accept -P).
    # -k returns 1K-blocks; convert to GB with awk.
    free_gb=$(df -Pk / | awk 'NR==2 {print int($4/1048576)}')
    if [ -n "$free_gb" ] && [ "$free_gb" -lt 10 ]; then
        echo "WARNING: Only ${free_gb}GB free on /. Matrix run may fail with 'no space left on device'."
        echo "Free >=10GB or run 'docker system prune -a' before --matrix."
        # Do not exit — let the user decide
    fi

    # Matrix mode implies Docker mode — credentials are set here (no earlier block sets them).
    USE_DOCKER=true
    export WP_ADMIN_USER="${WP_ADMIN_USER:-admin}"
    export WP_ADMIN_PASS="${WP_ADMIN_PASS:-password}"
fi

# Function to ensure npm dependencies
ensure_dependencies() {
    if [ ! -d "node_modules" ]; then
        echo "Installing npm dependencies..."
        npm install
    fi

    # Check if Playwright browsers are installed
    if ! npx playwright --version > /dev/null 2>&1; then
        echo "Installing Playwright browsers..."
        npx playwright install chromium
    fi
}

# Function to start Docker environment
start_docker_env() {
    echo "Starting EDD E2E Docker environment..."
    echo "  PHP version:      ${TEST_PHP_VERSION}"
    echo "  WP version:       ${WP_VERSION:-latest (floating tag)}"
    echo "  Elementor:        ${ELEMENTOR_VERSION}"
    echo "  Port:             ${WP_PORT}"
    echo ""

    # Export version vars so docker compose template vars resolve correctly
    export WP_VERSION ELEMENTOR_VERSION TEST_PHP_VERSION

    # Start db and wordpress
    local up_rc=0
    docker compose -f docker-compose.e2e.yml up -d db wordpress
    up_rc=$?
    if [ $up_rc -ne 0 ]; then
        return $up_rc
    fi

    echo "Waiting for WordPress container to be healthy..."
    health_wait=0
    wp_container="${COMPOSE_PROJECT_NAME:-edd-e2e}-wordpress-1"
    until [ "$(docker inspect -f '{{.State.Health.Status}}' "$wp_container" 2>/dev/null)" == "healthy" ]; do
        sleep 2
        health_wait=$((health_wait + 2))
        if [ $health_wait -ge 120 ]; then
            echo "ERROR: WordPress container ($wp_container) did not become healthy within 120 seconds"
            return 1
        fi
    done

    echo "Running WordPress setup..."
    local setup_rc=0
    docker compose -f docker-compose.e2e.yml --profile setup run --rm -T --volume "$(pwd)/e2e:/e2e-output" wpcli
    setup_rc=$?
    if [ $setup_rc -ne 0 ]; then
        return $setup_rc
    fi

    echo ""
    echo "============================================"
    echo "  EDD E2E Docker Environment Ready!"
    echo "============================================"
    echo ""
    echo "  WordPress: http://localhost:${WP_PORT}"
    echo "  Admin:     http://localhost:${WP_PORT}/wp-admin"
    echo ""
    echo "  Username:  ${WP_ADMIN_USER}"
    echo "  Password:  (hidden)"
    echo ""
}

# Function to run the full matrix loop.
# Iterates cells from e2e/matrix.json[LIST_NAME] serially.
# Each cell tears down volumes before starting so a prior cell's WP/PHP version can't leak into this one.
run_matrix() {
    local list_name="${1:-full}" # default matches the call-site default (full)
    local matrix_file="e2e/matrix.json"

    if [ ! -f "$matrix_file" ]; then
        echo "Error: $matrix_file not found. Expected at plugin root."
        exit 1
    fi

    # Validate list name exists in matrix.json and has a cells array.
    if ! jq -e ".[\"$list_name\"].cells" "$matrix_file" >/dev/null 2>&1; then
        echo "Error: Matrix list '$list_name' not found in $matrix_file (or missing 'cells' array)."
        echo "Available lists: $(jq -r 'keys[]' "$matrix_file" | tr '\n' ' ')"
        exit 1
    fi

    # Read scope from matrix.json — "compat" runs @compat only, "all" runs everything.
    # --full CLI flag overrides scope to "all".
    local scope
    scope=$(jq -r ".[\"$list_name\"].scope // \"compat\"" "$matrix_file")
    if [ "$MATRIX_FULL" = true ]; then
        scope="all"
    fi

    local cells_json
    cells_json=$(jq -c ".[\"$list_name\"].cells[]" "$matrix_file")

    local pass_count=0
    local fail_count=0
    local results=()
    local failed_cells=()
    local start_time
    start_time=$(date +%s)

    # Read EDD version from plugin header.
    local edd_version
    edd_version=$(grep -m1 '^ \* Version:' easy-digital-downloads.php 2>/dev/null | sed 's/.*Version: *//' || echo "unknown")

    # Export Docker env so setup step works in matrix mode.
    export EDD_E2E_DOCKER=1
    export WP_BASE_URL="http://localhost:${WP_PORT}/"
    export WP_ADMIN_USER="${WP_ADMIN_USER}"
    export WP_ADMIN_PASS="${WP_ADMIN_PASS}"

    ensure_dependencies

    # Clear any stale env marker left by a prior single-cell `npm run test:e2e`.
    # Each matrix cell provisions its own environment; a leftover e2e/.docker-env
    # would make playwright's globalSetup short-circuit ("Reusing existing Docker
    # environment") and run against a wiped/absent site.
    rm -f e2e/.docker-env

    # Clear stale per-cell JUnit XML so a crash-before-write can't leave a prior
    # cell's results for the zero-test guard to misread.
    rm -f e2e/results/*.xml

    echo ""
    echo "============================================"
    echo "  Matrix run: $list_name"
    echo "  $(jq ".[\"$list_name\"].cells | length" "$matrix_file") cells — scope: $scope — serial execution"
    echo "  EDD: $edd_version"
    echo "============================================"
    echo ""

    # Read cells from FD 3, not stdin — commands inside the loop (docker-compose, playwright)
    # consume stdin and would otherwise exhaust the here-string after one iteration.
    while IFS= read -r cell_json <&3; do
        local wp php el cell_id wp_env edd_mode
        wp=$(echo "$cell_json" | jq -r .wp)
        php=$(echo "$cell_json" | jq -r .php)
        el=$(echo "$cell_json" | jq -r .elementor)
        # EDD runtime mode for this cell: "lite" boots a real Lite runtime; anything
        # else (default "pro") is the normal Pro boot.
        edd_mode=$(echo "$cell_json" | jq -r '.edd // "pro"')
        # Normalize cell_id: strip non-alphanumeric from elementor version component.
        # Lite cells get a -lite suffix so their reports/XML never collide with the
        # byte-identical Pro cell_id; Pro cell_ids stay exactly as before.
        cell_id="${wp}-${php}-el${el//[^a-zA-Z0-9]/}"
        if [ "$edd_mode" = "lite" ]; then
            cell_id="${cell_id}-lite"
        fi

        echo ""
        echo "=== Matrix cell: $cell_id (WP $wp / PHP $php / Elementor $el / EDD $edd_version / mode $edd_mode) ==="

        # Matrix JSON uses "latest" for the WP floor of the matrix; resolve it
        # to the actual current stable major.minor (e.g., 6.9.4 → 6.9).
        if [ "$wp" = "latest" ]; then
            wp_env="$(resolve_wp_latest)" || wp_env=""
            if [ -n "$wp_env" ]; then
                echo "Resolved WP latest → $wp_env"
            else
                echo "Warning: could not resolve WP latest; falling back to floating tag"
                wp_env=""
            fi
        else
            wp_env="$wp"
        fi

        # --- Mandatory volume teardown (prevents cross-cell version contamination) ---
        # Must wipe wordpress_data volume before each cell; otherwise the prior WP
        # version's files persist and setup detects "already installed" → skips
        # re-install → tests run against the wrong version silently.
        WP_VERSION="$wp_env" TEST_PHP_VERSION="$php" ELEMENTOR_VERSION="$el" \
            docker compose -f docker-compose.e2e.yml down -v >/dev/null 2>&1 || true

        # --- Start environment for this cell ---
        export WP_VERSION="$wp_env"
        export TEST_PHP_VERSION="$php"

        # Recalculate CLI tag per cell (PHP 8.0 needs an older CLI image).
        case "$php" in
            8.0) export WP_CLI_TAG="cli-2-php8.0" ;;
            *)   export WP_CLI_TAG="cli-2.12-php${php}" ;;
        esac
        export ELEMENTOR_VERSION="$el"

        # Set the Lite-mode flag explicitly for EVERY cell ("1" for lite, "" otherwise)
        # BEFORE start_docker_env, since the wpcli setup writes the wp-config constant
        # inside it. An explicit per-iteration set (not a sticky export) prevents Lite
        # leaking into a later Pro cell.
        if [ "$edd_mode" = "lite" ]; then
            export EDD_E2E_LITE_MODE="1"
        else
            export EDD_E2E_LITE_MODE=""
        fi

        local setup_rc=0
        set +e
        start_docker_env
        setup_rc=$?
        set -e

        if [ "$setup_rc" -ne 0 ]; then
            fail_count=$((fail_count + 1))
            results+=("FAIL  $cell_id (setup failed)")
            failed_cells+=("$cell_json")
            WP_VERSION="$wp_env" TEST_PHP_VERSION="$php" ELEMENTOR_VERSION="$el" \
                docker compose -f docker-compose.e2e.yml down -v >/dev/null 2>&1 || true
            rm -f e2e/.docker-env
            continue
        fi

        # EDD_MATRIX_CELL is picked up by playwright.config.js to set per-cell
        # report paths (HTML + JUnit XML).  Do NOT pass --reporter on the CLI
        # as that would override the config and use default (non-cell) paths.
        # Clear only THIS cell's stale XML before it runs so the guard reads
        # only this cell's freshly-written XML (not a prior cell's leftover).
        rm -f "e2e/results/${cell_id}.xml"

        local cell_rc=0
        set +e
        # Branch order is PINNED: the lite check comes FIRST. A lite cell ALWAYS runs
        # the @lite-scoped specs regardless of list — the existing Pro specs are not
        # lite-aware and would false-fail under a real Lite boot.
        if [ "$edd_mode" = "lite" ]; then
            EDD_MATRIX_CELL="$cell_id" npx playwright test --config=e2e/playwright.config.js --grep "@lite"
        elif [ "$scope" = "all" ]; then
            EDD_MATRIX_CELL="$cell_id" npx playwright test --config=e2e/playwright.config.js
        else
            EDD_MATRIX_CELL="$cell_id" npx playwright test --config=e2e/playwright.config.js --grep "@compat"
        fi
        cell_rc=$?
        set -e

        # --- Teardown this cell's volumes ---
        WP_VERSION="$wp_env" TEST_PHP_VERSION="$php" ELEMENTOR_VERSION="$el" \
            docker compose -f docker-compose.e2e.yml down -v >/dev/null 2>&1 || true
        # Clear the env marker too: `down -v` wipes the volume but leaves
        # e2e/.docker-env, which would make the next cell "reuse" a gone site.
        rm -f e2e/.docker-env

        local cell_executed
        cell_executed=$(count_executed_tests "e2e/results/${cell_id}.xml")
        if [ "$cell_rc" -eq 0 ] && [ "$cell_executed" -ge 1 ]; then
            pass_count=$((pass_count + 1))
            results+=("PASS  $cell_id")
        else
            fail_count=$((fail_count + 1))
            results+=("FAIL  $cell_id ($cell_executed executed, rc=$cell_rc)")
            failed_cells+=("$cell_json")
        fi

    done 3<<< "$cells_json"

    # --- Retry failed cells once (opt-in via --retry) ---
    if [ "$MATRIX_RETRY" = true ] && [ ${#failed_cells[@]} -gt 0 ]; then
        echo ""
        echo "============================================"
        echo "  Retrying ${#failed_cells[@]} failed cell(s)..."
        echo "============================================"

        for cell_json in "${failed_cells[@]}"; do
            local wp php el cell_id wp_env edd_mode
            wp=$(echo "$cell_json" | jq -r .wp)
            php=$(echo "$cell_json" | jq -r .php)
            el=$(echo "$cell_json" | jq -r .elementor)
            # EDD runtime mode for this cell: "lite" boots a real Lite runtime; anything
            # else (default "pro") is the normal Pro boot.
            edd_mode=$(echo "$cell_json" | jq -r '.edd // "pro"')
            # Lite cells get a -lite suffix so their reports/XML never collide with the
            # byte-identical Pro cell_id; Pro cell_ids stay exactly as before.
            cell_id="${wp}-${php}-el${el//[^a-zA-Z0-9]/}"
            if [ "$edd_mode" = "lite" ]; then
                cell_id="${cell_id}-lite"
            fi

            echo ""
            echo "=== RETRY: $cell_id (WP $wp / PHP $php / Elementor $el / EDD $edd_version / mode $edd_mode) ==="

            if [ "$wp" = "latest" ]; then
                wp_env="$(resolve_wp_latest)" || wp_env=""
                if [ -n "$wp_env" ]; then
                    echo "Resolved WP latest → $wp_env"
                else
                    echo "Warning: could not resolve WP latest; falling back to floating tag"
                    wp_env=""
                fi
            else
                wp_env="$wp"
            fi

            WP_VERSION="$wp_env" TEST_PHP_VERSION="$php" ELEMENTOR_VERSION="$el" \
                docker compose -f docker-compose.e2e.yml down -v >/dev/null 2>&1 || true

            export WP_VERSION="$wp_env"
            export TEST_PHP_VERSION="$php"
            case "$php" in
                8.0) export WP_CLI_TAG="cli-2-php8.0" ;;
                *)   export WP_CLI_TAG="cli-2.12-php${php}" ;;
            esac
            export ELEMENTOR_VERSION="$el"

            # Set the Lite-mode flag explicitly for EVERY retried cell ("1" for lite,
            # "" otherwise) BEFORE start_docker_env. The retry loop re-derives its own
            # env, so a sticky export would Lite-boot a retried Pro cell.
            if [ "$edd_mode" = "lite" ]; then
                export EDD_E2E_LITE_MODE="1"
            else
                export EDD_E2E_LITE_MODE=""
            fi

            local setup_rc=0
            set +e
            start_docker_env
            setup_rc=$?
            set -e

            if [ "$setup_rc" -ne 0 ]; then
                WP_VERSION="$wp_env" TEST_PHP_VERSION="$php" ELEMENTOR_VERSION="$el" \
                    docker compose -f docker-compose.e2e.yml down -v >/dev/null 2>&1 || true
                rm -f e2e/.docker-env
                continue
            fi

            # Clear only THIS cell's stale XML before it runs so the guard reads
            # only this cell's freshly-written XML (not a prior cell's leftover).
            rm -f "e2e/results/${cell_id}.xml"

            local cell_rc=0
            set +e
            # Branch order is PINNED: the lite check comes FIRST (see main loop).
            # A lite cell ALWAYS runs the @lite-scoped specs regardless of list.
            if [ "$edd_mode" = "lite" ]; then
                EDD_MATRIX_CELL="$cell_id" npx playwright test --config=e2e/playwright.config.js --grep "@lite"
            elif [ "$scope" = "all" ]; then
                EDD_MATRIX_CELL="$cell_id" npx playwright test --config=e2e/playwright.config.js
            else
                EDD_MATRIX_CELL="$cell_id" npx playwright test --config=e2e/playwright.config.js --grep "@compat"
            fi
            cell_rc=$?
            set -e

            WP_VERSION="$wp_env" TEST_PHP_VERSION="$php" ELEMENTOR_VERSION="$el" \
                docker compose -f docker-compose.e2e.yml down -v >/dev/null 2>&1 || true
            # Clear the env marker too (see per-cell teardown above).
            rm -f e2e/.docker-env

            local cell_executed
            cell_executed=$(count_executed_tests "e2e/results/${cell_id}.xml")
            if [ "$cell_rc" -eq 0 ] && [ "$cell_executed" -ge 1 ]; then
                # Flip FAIL → PASS in results
                pass_count=$((pass_count + 1))
                fail_count=$((fail_count - 1))
                # The original FAIL entry carries a reason suffix (e.g.
                # "(N executed, rc=…)"), so match the exact id OR id-followed-by-space.
                # Avoids a prefix glob where e.g. 6.7-8.1 could match 6.7-8.10.
                for i in "${!results[@]}"; do
                    case "${results[$i]}" in
                        "FAIL  $cell_id" | "FAIL  $cell_id "* )
                            results[$i]="PASS  $cell_id (retry)"
                            break
                            ;;
                    esac
                done
                echo "=== RETRY PASSED: $cell_id ==="
            else
                echo "=== RETRY FAILED: $cell_id ($cell_executed executed, rc=$cell_rc) ==="
            fi
        done
    fi

    local end_time elapsed_total elapsed_s elapsed_m
    end_time=$(date +%s)
    elapsed_total=$(( end_time - start_time ))
    elapsed_m=$(( elapsed_total / 60 ))
    elapsed_s=$(( elapsed_total % 60 ))

    echo ""
    echo "============================================"
    echo "  Matrix Summary: $list_name (EDD $edd_version)"
    echo "============================================"
    printf '%s\n' "${results[@]}"
    echo ""
    echo "Passed: $pass_count / $((pass_count + fail_count))  (${elapsed_m}m ${elapsed_s}s total)"
    echo ""

    if [ -d "playwright-report/matrix" ]; then
        echo "Per-cell HTML reports:"
        for d in playwright-report/matrix/*/; do
            echo "  $d"
        done
    fi
    if [ -d "e2e/results" ]; then
        echo "Per-cell JUnit XML:"
        for f in e2e/results/*.xml; do
            echo "  $f"
        done
    fi

    # Final cleanup: ensure no Docker state leaks into single-cell npm mode.
    docker compose -f docker-compose.e2e.yml down -v >/dev/null 2>&1 || true
    rm -f e2e/.docker-env

    [ $fail_count -eq 0 ] || exit 1
}

# Main execution
# MATRIX_MODE was already defaulted to true (before the preflight block) so a
# bare invocation runs the default matrix list. Matrix mode is the only mode.
if [ "$MATRIX_MODE" = true ]; then
    # When no --matrix flag was given, tell the user what a bare run is doing.
    if [ "$MATRIX_GIVEN" = false ]; then
        echo "Running default matrix (full) — see --help for options."
    fi

    # Ensure Docker is running before the matrix loop.
    ensure_docker_running

    # Default list for --matrix with no argument is full (most common use case).
    run_matrix "${MATRIX_LIST:-full}"
    exit $?
fi
