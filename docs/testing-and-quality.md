# Testing & quality

What every quality gate enforces, why, and how to run each one locally.

## Quality stack at a glance

Once the repository lives in the DiluxOne organisation, the pull request
checks run from [`.github/workflows/pull-request.yml`](../.github/workflows/pull-request.yml),
which calls the shared workflows in [`DiluxOne/.github`](https://github.com/DiluxOne/.github):
the **fast** suite first, then the Claude review, with the **slow** suite
alongside it. Until then, the Make targets below are the gates.

| Layer | Tool | Catches | Suite | Make target |
| --- | --- | --- | --- | --- |
| Conventions | shared `conventions` workflow, lychee | Branch name, PR title and commit headers; the description's What changes and Why; no "Generated with …" footer; broken relative doc links; retired product names. | conventions | `make docs-check` (links, names) |
| Syntax | `php -l` on PHP 8.0 to 8.5, the shipped files | Syntax the minimum PHP cannot parse. | fast | — |
| Unit tests | PHPUnit 9 + Brain Monkey + Mockery, same PHP matrix | Logic regressions without WordPress. | fast | `make test`, `make test-unit-min` |
| Coding style | PHPCS + WordPress Coding Standards + PHPCompatibilityWP (8.0+) | Style, naming, escaping, sanitising, nonces, prefixes, deprecated APIs, syntax newer than 8.0. | fast | `make lint` |
| Static analysis | PHPStan level 8 + phpstan-wordpress, **no baseline** | Types, dead code, undefined methods. | fast | `make stan` |
| Taint analysis | Psalm + psalm-plugin-wordpress (taint only) | Request data reaching `echo`, SQL, `header()`, files without an escaper. | fast | `make psalm` |
| i18n | `wp i18n make-pot` on the shipped tree; `msgfmt`/`msgcmp` | Missing translator comments, dynamic domains, concatenated strings; an incomplete, fuzzy or stale locale. | fast | `make i18n`, `make i18n-check` |
| Plugin Check | wordpress/plugin-check on the shipped tree | What the wordpress.org review team runs. | fast | `make plugin-check` |
| Readme and versions | shared `release-markers.sh` | Readme headers; `Version:`, `WPMUS_VERSION` and `Stable tag:` in line and naming the last release (or the one being released, in its release pull request). | fast | — |
| Claude review | shared `claude-review` workflow | [`architecture.md`](architecture.md), [`AGENTS.md`](../AGENTS.md) and the WordPress review profile; rates risk and complexity. | after fast | `make review-local` |
| Integration tests | PHPUnit on the wp-env tests network | Behaviour against real WordPress Multisite and MySQL: hooks, options, memberships, cron, uninstall. | slow | `make test-integration` |
| End-to-end tests | Playwright on the wp-env dev network | Every screen, every trigger and action as a person uses them, permissions, the queue through WP-Cron, uninstall, layout invariants; plus the single-site check. | slow | `make test-e2e` |
| Coverage | Xdebug on the wp-env network, `tests/coverage/` | Code no test runs: each layer's share of the lines that ship, and all together. | local | `make coverage` |
| Load | PHPUnit on the wp-env tests network, `tests/Load/` | A big network with the plugin's real limits: the request that starts a sync stays fast, each background run stays within its time and memory, no membership is missed or doubled. Minutes, so not in `pre-pr`. | local | `make test-load` |
| Visual baselines | Playwright `toHaveScreenshot` | A screen that changed look without a rule breaking. Local only: a baseline is one machine's rendering. | — | `make test-visual`, `make test-visual-update` |

A change carries its tests **at every layer it touches**, in the same pull
request: a unit test for logic that stands alone, an integration test on a
network for what needs WordPress, an end-to-end test for what a person does
or sees, and, when a screen changes, the layout run, the baselines retaken on
purpose (`make test-visual-update`) and the listing screenshots
(`make screenshots`). Which test covers which feature:
[`tests/e2e/COVERAGE.md`](../tests/e2e/COVERAGE.md); a new feature or state
adds its row.

**What runs when (in the organisation).** A pull request that changes no code
runs only the fast checks and the review; the integration and end-to-end
suites and Plugin Check show as skipped, which the ruleset accepts. "Code"
is the `code` list of the organisation's policy plus this repository's
[`.github/review-policy.yml`](../.github/review-policy.yml). A push to `main`
runs everything unless its tree is the one the pull request tested; once a
week everything runs against today's WordPress.

## Unit tests

[`tests/Unit/`](../tests/Unit/), pure PHP: Brain Monkey stubs WordPress
functions and Mockery mocks the repositories, so the engine's every branch
runs without a database. [`tests/bootstrap.php`](../tests/bootstrap.php)
defines `ABSPATH` and `WPMUS_VERSION`.

```bash
make test            # on the newest PHP
make test-unit-min   # on PHP 8.0
```

Name a test after the class it tests; keep `$_GET`, the database and real
hooks out (that is integration).

## Integration tests

[`tests/Integration/`](../tests/Integration/), inside the wp-env tests
container, against the tests **network** (8899). The bootstrap loads
WordPress, refuses to run on a single site, and reads the tests host from
`.wp-env.json` (`WPMUS_TESTS_HOST` overrides it). Every test starts with the
three toggles off and deletes the sites and users it made.

```bash
make env
make test-integration
```

## End-to-end tests

[`tests/e2e/`](../tests/e2e/): Playwright and Chromium against the dev
network (8898). How it is built, what it needs and why:
[`tests/e2e/README.md`](../tests/e2e/README.md).

```bash
make test-e2e          # the network suite, then the single-site check
make test-layout       # only the layout measurements
make test-visual       # compare with the baselines in tests/e2e/snapshots/
make screenshots       # retake .wordpress-org/screenshot-1..6.png
```

The layout measurements (overlap, overflow, blank boxes, hidden elements,
sideways scroll, at 1600/1280/960/782 px) need no baseline and run with the
suite and in CI; the pictures are opt-in because a baseline belongs to the
machine that took it. Read `git diff --stat tests/e2e/snapshots` before
committing retaken pictures: that diff is the review of the change.

In CI the shared workflow runs `npx playwright test`: the network suite only.
The single-site check needs the Plugin Check environment and runs locally
(`make test-e2e`); its logic is covered in CI by `RequirementsCheckerTest`
and `PluginTest`.

## Coverage

`make coverage` measures which lines of what ships (`src/`, the main file,
`uninstall.php`, `legacy-deprecated.php`) each layer runs, and all layers
together. It restarts the wp-env network with Xdebug in coverage mode, runs
the unit, integration and end-to-end suites (the network suite, then the
single-site check on the Plugin Check environment), and prints a table per
file with the lines no layer runs; then it restarts both environments
without Xdebug, whether the run passed or not. The end-to-end layer is recorded per
request by [`tests/e2e/mu-plugin/wpmus-coverage.php`](../tests/e2e/mu-plugin/wpmus-coverage.php),
a must-use plugin that does nothing unless Xdebug is in coverage mode and
`build/coverage/e2e/` exists. [`tests/coverage/report.php`](../tests/coverage/report.php)
counts a line the way PHPUnit does: code to the static analysis and runnable
to PHP.

```bash
make coverage          # all three layers, then the table; fails below the floors
make coverage-report   # the table again from what build/coverage/ holds
make env               # back to the network without Xdebug
```

The floors are `COVERAGE_MIN` (all layers together, 100) and
`COVERAGE_LAYER_MIN` (each layer). New code comes with the tests that run it
at every layer it touches; a floor is never lowered to let a change in. The
only lines excluded are the `exit` of the direct-access guards
(`// @codeCoverageIgnore`), which no request through WordPress can reach.

## Load

`make test-load` builds a big network on the tests site (`LOAD_USERS`
accounts, 3000 by default, written straight to the users table as an import
would; `LOAD_SITES` sites, 10 by default) and runs the plugin on it with its
real batch size and time limit, no filter shrinking them. A manual sync of
every site and a new site's sync each go to the background; every run of the
queue is timed and its memory measured, and each site must end with every
user exactly once, also after a second pass. It prints what it measured:

```text
Manual sync: 30000 user-site pairs. Start 0.01s; … background runs in …s (… pairs/s); slowest run …s; most memory one run added … MB.
```

Its bounds: the starting request under 5 s; a run under the time limit plus
15 s; a run adding under 64 MB, whatever the network's size. The speed itself
is printed, not judged: it depends on the database's disk far more than on
the plugin (core's `add_user_to_blog()` commits each of its writes).

```bash
make test-load                               # 3000 users × 10 sites
make test-load LOAD_USERS=10000 LOAD_SITES=50
```

## PHPCS, PHPStan, Psalm

[`phpcs.xml.dist`](../phpcs.xml.dist), [`phpstan.neon`](../phpstan.neon) with
[`phpstan-bootstrap.php`](../phpstan-bootstrap.php), [`psalm.xml`](../psalm.xml).
The `src/` tree is PSR-4 (StudlyCase files), so the WPCS file-naming sniffs
are off there; everything else is on. PHPStan runs at level 8 with no
baseline: fix the type, do not record it. Psalm runs in taint mode only
(types are PHPStan's job); a finding is fixed by the right escaper, not a
suppression.

## Translations

`languages/wpm-user-sync.pot` ships; eight complete locales (`es_AR`,
`es_ES`, `es_MX`, `pt_BR`, `pt_PT`, `fr_FR`, `de_DE`, `it_IT`) live beside it
as `.po` and `.mo`, the source for translate.wordpress.org. After changing a
string: `make i18n-update`, translate the new entries in every `.po`,
`make i18n-mo`, and `make i18n-check` must pass (no untranslated, fuzzy or
missing entry).

## Plugin Check

`make plugin-check` builds the dist and runs wordpress.org's Plugin Check on
it in its own wp-env (8900/8901). `wp plugin check` exits 0 whatever it
finds, so the target reads the table and fails on any ERROR row.

One warning stays by design: `trademarked_term` on the slug `wpm-user-sync`
("wp"). The slug is permanent since the plugin is published, which is why
the display name no longer carries it.

## Running everything at once

```bash
make check     # lint + stan + psalm + unit tests
make pre-pr    # check, PHP 8.0 units, i18n, docs, coverage (the three suites and the single-site check, with their floors), Plugin Check, local review
```
