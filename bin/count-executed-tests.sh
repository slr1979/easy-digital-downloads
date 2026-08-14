#!/bin/bash

# Single source of truth for counting executed (non-infra) tests in a Playwright
# JUnit XML report. Sourced by bin/run-e2e-matrix.sh; sourcing has no side effects
# — it defines the one function below and runs nothing on its own.
#
# count_executed_tests sums (tests - skipped) over every <testsuite> whose name
# does NOT start with "global/" (the global/ suites are setup/teardown infra, not
# real tests). It prints the integer total to stdout. A missing or empty file
# prints 0.
#
# Portability: Playwright's JUnit reporter emits each <testsuite ...> open tag on
# its own line, so the default line-based record separator is sufficient. We avoid
# a multi-char RS (RS="<testsuite ") because that is non-POSIX and mawk (the
# default awk on Debian/Ubuntu CI) mishandles it. As belt-and-suspenders, the awk
# loops over EACH <testsuite ...> tag on a line, so it still works if 1+ tags ever
# share a line. The attribute regexes are order-independent (name/tests/skipped can
# appear in any order within the tag). This works on mawk, gawk, and BSD/macOS awk.
count_executed_tests() {
    local xml="$1"
    [ -f "$xml" ] || { echo 0; return; }
    awk '
        /<testsuite / {
            line=$0
            while (match(line, /<testsuite [^>]*>/)) {
                seg=substr(line, RSTART, RLENGTH)
                # Save tag end-offset before inner match() calls overwrite RSTART/RLENGTH.
                tag_end=RSTART+RLENGTH
                name=""; t=0; s=0
                if (match(seg, /name="[^"]*"/))    { name=substr(seg, RSTART+6, RLENGTH-7) }
                if (match(seg, /tests="[0-9]+"/))   { t=substr(seg, RSTART+7, RLENGTH-8) }
                if (match(seg, /skipped="[0-9]+"/)) { s=substr(seg, RSTART+9, RLENGTH-10) }
                if (name !~ /^global\//)            { sum += (t - s) }
                line=substr(line, tag_end)
            }
        }
        END { print sum+0 }
    ' "$xml"
}
