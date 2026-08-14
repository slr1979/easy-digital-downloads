#!/bin/bash

# Unit test for count_executed_tests (bin/count-executed-tests.sh).
# Sources the helper and asserts on eight JUnit-XML fixtures:
#   (a) global/ + non-global suites → counts only non-global (tests - skipped)
#   (b) only global/ suites          → 0
#   (c) missing file (inline check)  → 0
#   (d) two <testsuite> tags on ONE line (global/ + real) → counts only the real one
#   (e) real Playwright attribute order/extra attrs        → counts (tests - skipped)
#   (f) existing but empty file      → 0
#   (g) <testsuite> with no skipped attribute → defaults skipped to zero
#   (h) <testsuite> open tag wrapped across lines → silently skips (known limit)
#
# Run: bin/tests/count-executed-tests.test.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=bin/count-executed-tests.sh
. "${SCRIPT_DIR}/../count-executed-tests.sh"

tmp="$(mktemp -d "${TMPDIR:-/tmp}/count-executed-tests.XXXXXX")"
trap 'rm -rf "$tmp"' EXIT

fail=0
assert_eq() {
    # $1 = label, $2 = expected, $3 = actual
    if [ "$2" = "$3" ]; then
        echo "PASS: $1 (got $3)"
    else
        echo "FAIL: $1 (expected $2, got $3)"
        fail=1
    fi
}

# Fixture (a): one global/ suite (infra) + two non-global suites.
# Non-global executed = (5-1) + (3-0) = 7. The global/ suite is excluded.
cat > "$tmp/mixed.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
<testsuite name="global/global.setup.js" tests="2" skipped="0" failures="0"></testsuite>
<testsuite name="compat/checkout-smoke.spec.js" tests="5" skipped="1" failures="0"></testsuite>
<testsuite name="checkout/checkout.spec.js" tests="3" skipped="0" failures="0"></testsuite>
</testsuites>
XML

# Fixture (b): only global/ suites → 0.
cat > "$tmp/global-only.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
<testsuite name="global/global.setup.js" tests="2" skipped="0" failures="0"></testsuite>
<testsuite name="global/taxes.setup.js" tests="1" skipped="0" failures="0"></testsuite>
</testsuites>
XML

# Fixture (d): TWO <testsuite> tags on ONE line (one global/, one real).
# Only the real suite counts: (4-1) = 3. The global/ suite on the same line is excluded.
cat > "$tmp/one-line.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
<testsuite name="global/global.setup.js" tests="2" skipped="0" failures="0"></testsuite><testsuite name="checkout/checkout.spec.js" tests="4" skipped="1" failures="0"></testsuite>
</testsuites>
XML

# Fixture (e): real Playwright JUnit attribute order/extra attrs (name first, then
# timestamp/hostname before tests/skipped). Executed = (5-1) = 4.
cat > "$tmp/real-order.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
<testsuite name="checkout/foo.spec.js" timestamp="2024-01-01T00:00:00.000Z" hostname="localhost" tests="5" failures="0" skipped="1" time="3.2" errors="0">
</testsuite>
</testsuites>
XML

# Fixture (f): existing but empty file → 0.
: > "$tmp/empty.xml"

# Fixture (g): <testsuite> with tests="3" and NO skipped attribute → 3 (default-zero fallback).
cat > "$tmp/no-skipped.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
<testsuite name="checkout/bar.spec.js" tests="3" failures="0"></testsuite>
</testsuites>
XML

# Fixture (h): <testsuite open tag whose closing > is on the NEXT line (attributes wrapped).
# Known/accepted silent-skip limitation — Playwright emits the open tag on one line,
# so a multi-line open tag is not matched and counts as 0.
cat > "$tmp/wrapped-tag.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
<testsuite name="checkout/baz.spec.js" tests="2"
           skipped="0" failures="0"></testsuite>
</testsuites>
XML

assert_eq "(a) mixed global + non-global counts non-global only" "7" "$(count_executed_tests "$tmp/mixed.xml")"
assert_eq "(b) global-only suites count as zero"                 "0" "$(count_executed_tests "$tmp/global-only.xml")"
assert_eq "(c) missing file counts as zero"                      "0" "$(count_executed_tests "$tmp/does-not-exist.xml")"
assert_eq "(d) two testsuites on one line counts real only"      "3" "$(count_executed_tests "$tmp/one-line.xml")"
assert_eq "(e) real Playwright attribute order counts correctly" "4" "$(count_executed_tests "$tmp/real-order.xml")"
assert_eq "(f) empty file counts as zero"                        "0" "$(count_executed_tests "$tmp/empty.xml")"
assert_eq "(g) no skipped attribute defaults to zero skipped"    "3" "$(count_executed_tests "$tmp/no-skipped.xml")"
assert_eq "(h) wrapped open tag silently skips (known limit)"    "0" "$(count_executed_tests "$tmp/wrapped-tag.xml")"

if [ "$fail" -ne 0 ]; then
    echo "RESULT: FAIL"
    exit 1
fi
echo "RESULT: PASS"
