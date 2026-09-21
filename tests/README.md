# Easy Digital Downloads Unit Tests [![Build status](https://badge.buildkite.com/e318e1649f3a28f4029272231b926126d5664837d575cd507a.svg)](https://buildkite.com/sandhills-development-llc/easy-digital-downloads)


This folder contains all the tests for Easy Digital Downloads.

## Running Tests
### How to run the tests locally
The ideal way to run the unit tests locally is by using the included Docker Compose files for the stack and for PHPUnit.

Requirements:
- Docker (19.03.13 or newer)
- Docker Compose (1.25.5 or newer)

To run the tests, use the following command:
```
bin/run-tests-local.sh -p 8.2 -w latest
```

The flags `-p` and `-w` control the PHP and WordPress versions for the tests, respectively.

This command sends the `phpunit` command to the `wordpress_phpunit` container.

### Running Tests with EDD Recurring
To run tests that require EDD Recurring (such as subscription-related IPN tests), you can use the `--extra` flag:

```bash
bin/run-tests-local.sh -p 8.2 -w latest --extra recurring
```

**Important:** Running tests with EDD Recurring requires a GitHub token with access to private repositories. The token is resolved automatically, in this order:

1. `COMPOSER_AUTH` — if set, it always wins (this is how CI passes its secret).
2. **The GitHub CLI** — `gh auth token`, if you are logged in via `gh auth login`. This is the recommended local path; no manual export needed.
3. Composer's global `auth.json` (`~/.config/composer/auth.json`, or `~/.composer/auth.json` on macOS).

So the simplest local workflow is just:

```bash
gh auth login          # once
bin/run-tests-local.sh -p 8.2 -w latest --extra recurring
```

If you prefer to pass the token explicitly (or don't use the `gh` CLI):

```bash
export COMPOSER_AUTH='{"github-oauth":{"github.com":"your_github_token_here"}}'
bin/run-tests-local.sh -p 8.2 -w latest --extra recurring
```

You can create a GitHub token at https://github.com/settings/tokens with the `repo` scope. If no token can be resolved, the script fails early — before Docker starts — with an actionable message.

When `--extra recurring` is used:
1. The test scripts will download and install EDD Recurring from GitHub
2. The test bootstrap will automatically load and activate EDD Recurring
3. Tests requiring `EDD_Recurring` or `EDD_Subscription` classes will run (not be skipped)

Example running PayPal IPN tests with Recurring (token resolved from `gh`):
```bash
bin/run-tests-local.sh -p 8.2 -w latest --extra recurring -f 'EDD\\Tests\\Gateways\\PayPal\\IPN'
```

## Compatibility Matrix
To run the tests across several PHP/WordPress/multisite combinations at once, use `bin/run-phpunit-matrix.sh`. It wraps `bin/run-tests-local.sh`, driving each cell through it serially (each cell manages its own Docker lifecycle), and the cell lists mirror the PHPUnit matrix in `.github/workflows/CI.yml`.

Requirements:
- `jq` (macOS: `brew install jq` · Linux: `apt-get install jq` · Windows: `choco install jq` · or https://jqlang.github.io/jq/download/)
- Docker

The cell lists are defined in `tests/phpunit-matrix.json`, which is the authoritative source (CI's set-matrix job reads it directly). Each cell is a PHP version, a WordPress version, and a multisite flag (`ms`). The available lists are `pr` (default), `full`, and `coverage` — see the JSON for the exact cells in each.

Running the script with no options runs the default (`pr`) matrix list. To run a matrix list:
```bash
# Default (pr) matrix — 4 cells
bin/run-phpunit-matrix.sh

# Same as above, explicit --matrix flag
bin/run-phpunit-matrix.sh --matrix

# Same as above, explicit list name
bin/run-phpunit-matrix.sh --matrix=pr

# Full 5-cell matrix
bin/run-phpunit-matrix.sh --matrix=full

# Coverage list (PHP 8.0 / WP 6.7)
bin/run-phpunit-matrix.sh --matrix=coverage
```

When the script is run with no options, or `--matrix` is given with no value, the `pr` list runs by default. After the run, a summary lists each cell as `PASS`/`FAIL` with the total pass count and elapsed time.

Use `--retry` to retry any failed cells once after the initial pass:
```bash
bin/run-phpunit-matrix.sh --matrix --retry
```

The `-f`/`--filter` and `-x`/`--extra` flags are passed through to `run-tests-local.sh` (and on to PHPUnit's `--filter`) for every cell:
```bash
# Filter the pr matrix to 'Discounts' tests
bin/run-phpunit-matrix.sh --matrix --filter 'Discounts'

# Run the pr matrix with EDD Recurring included
bin/run-phpunit-matrix.sh --matrix --extra recurring
```

See `bin/run-phpunit-matrix.sh --help` for the full option list.

### Multisite
The `ms:1` cells (and the `-m` single-cell flag) run the tests as true multisite. `bin/run-tests-local.sh` now forwards `WP_MULTISITE` into the Docker container, so `-m` works on `run-tests-local.sh` directly as well:
```bash
bin/run-tests-local.sh -p 8.3 -w latest -m
```

## Writing Tests
For more information on how to write PHPUnit Tests, see [PHPUnit's Website](https://docs.phpunit.de/en/9.6/writing-tests-for-phpunit.html). Use the 9.6 docs, not `latest` — we run PHPUnit 9.6.

### Filtering
While writing tests, you may found it helpful to only run the specific tests, file or namespace you are working in. To do this you can use the `-f` filter.

```
# Test for a specific test
bin/run-tests-local.sh -p 8.2 -w latest -f test_full_function_symbol

# Test for any tests whose function starts with the string
bin/run-tests-local.sh -p 8.2 -w latest -f test_get_order_

# Test for a namespace or class
bin/run-tests-local.sh -p 8.2 -w latest -f 'EDD\\Tests\\Settings'
bin/run-tests-local.sh -p 8.2 -w latest -f 'EDD\\Tests\Settings\Setting'
