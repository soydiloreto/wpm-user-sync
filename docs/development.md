# Development

How to run the plugin from source, what you need, and the day-to-day
commands. Contribution rules: [`CONTRIBUTING.md`](../CONTRIBUTING.md). The
test and quality stack: [`testing-and-quality.md`](testing-and-quality.md).
How a change becomes a version: [`release.md`](release.md).

## What you need

| Tool | Why |
| --- | --- |
| **Docker** | Runs `wp-env` (the local networks) and the PHP toolchain (PHPCS, PHPStan, Psalm, PHPUnit, WP-CLI) without PHP on the host. |
| **Node.js 18+** and **npm** | `wp-env` and Playwright. |
| **GNU make** | Every task is a target; `make help` lists them. |
| **gettext** (`msgfmt`, `msgcmp`) | `make i18n-check`. |
| **`gh`** (optional) | Issues, pull requests and CI logs. |

Every PHP command runs inside an official Docker image as your host user, so
`vendor/` is not root-owned. With a full PHP CLI on the host (`dom`,
`mbstring`, `xml`, `xmlwriter`, `tokenizer`…), `make DOCKER=0 …` skips the
images.

## First run

```bash
git clone https://github.com/DiluxOne/diluxone-multisite-user-sync-wordpress.git
cd <its folder>
make install     # composer, npm, Playwright's Chromium
make env         # the dev network at http://localhost:8898, the tests network at :8899
```

Open <http://localhost:8898/wp-admin/network/> and sign in with `admin` /
`password`. The plugin is mounted from the checkout and network-activated;
its screens are under **User Sync** in Network Admin and, for super admins,
in each site's dashboard.

## The local networks

[`.wp-env.json`](../.wp-env.json) declares `"multisite": true`, so both wp-env
sites are **subdirectory networks** (subdomain networks need DNS the default
stack does not have):

| URL | What |
| --- | --- |
| <http://localhost:8898/> | The dev network's main site; the end-to-end suite runs here. |
| <http://localhost:8898/wp-admin/network/> | Network Admin. |
| `http://localhost:8898/<slug>/` | Each site you add. |
| <http://localhost:8899/> | The tests network; the integration suite boots it. |
| <http://localhost:8900/>, <http://localhost:8901/> | The Plugin Check environment (`make plugin-check`): plain single sites with the built plugin. The single-site end-to-end check uses 8901. |

The ports avoid the other DiluxOne plugins on the same machine
(diluxone-offload on 8888/8889 and 8896/8897, diluxone-users on
8892-8895). Tests read them from `.wp-env.json`, never from a literal.

The folder [`tests/e2e/mu-plugin/`](../tests/e2e/mu-plugin/) is mapped as
mu-plugins, as a folder: Docker leaves a placeholder owned by root behind a
file mapping, and wp-env then fails with `EACCES` when it copies WordPress
for a change of Xdebug mode. [`wpmus-e2e.php`](../tests/e2e/mu-plugin/wpmus-e2e.php)
does nothing until the end-to-end suite writes its network option
`wpmus_e2e_knobs`; [`wpmus-coverage.php`](../tests/e2e/mu-plugin/wpmus-coverage.php)
does nothing outside `make coverage`.

### Trying the triggers by hand

```bash
npx wp-env run cli wp site create --slug=alpha --title="Alpha"
npx wp-env run cli wp site option update wpmus_newSiteSync yes       # New Site
npx wp-env run cli wp site option update wpmus_newUserSync yes       # New User
npx wp-env run cli wp site option update wpmus_setUserRoleSync yes   # Role change
npx wp-env run cli wp user create ana ana@example.com                # joins every site
npx wp-env run cli wp user set-role ana editor --url=http://localhost:8898/alpha/
npx wp-env run cli wp cron event run wpmus_process_sync_queue       # move a queued sync on
```

## Day-to-day commands

| Command | What it does |
| --- | --- |
| `make help` | Every target with one line each (the default). |
| `make install` / `make update` | Dev dependencies. |
| `make env` / `make env-down` / `make env-clean` | Start, stop (the Plugin Check stack too), destroy the wp-env stack. |
| `make lint` / `make lint-fix` | PHPCS with the WordPress Coding Standards; PHPCBF. |
| `make stan` | PHPStan level 8, no baseline. |
| `make psalm` | Psalm taint analysis. |
| `make test` / `make test-unit` | The unit suite (no WordPress). |
| `make test-unit-min` | The unit suite on PHP 8.0, the minimum. |
| `make test-integration` | The integration suite on the tests network. |
| `make test-e2e` | Playwright on the dev network, then the single-site check. |
| `make test-e2e-single` | Only the single-site check. |
| `make test-layout` | Only the layout measurements. |
| `make test-visual` / `make test-visual-update` | Compare every screen with its baseline picture / retake them. |
| `make screenshots` | Retake the wordpress.org listing screenshots from the real screens. |
| `make test-load` | The load test: a big network with the real limits; prints the timings (`LOAD_USERS`, `LOAD_SITES`). |
| `make coverage` / `make coverage-report` | Line coverage per layer and all together, on the network with Xdebug; fails below the floors ([testing-and-quality.md](testing-and-quality.md#coverage)). |
| `make check` | The fast gates: lint, stan, psalm, unit tests. |
| `make i18n` / `make i18n-update` / `make i18n-mo` / `make i18n-check` | Refresh the `.pot`; merge it into every `.po`; compile the `.mo`; fail on an incomplete, fuzzy, stale or malformed locale. |
| `make docs-check` | Relative links in the Markdown resolve; no retired product name is back. |
| `make dist` / `make zip` | `build/wpm-user-sync/`, exactly what ships (fails on untracked files or `vendor/`); its zip. |
| `make plugin-check` | wordpress.org's Plugin Check on the dist, in a throwaway wp-env; an ERROR fails. `make plugin-check-all` lists warnings too. |
| `make deploy-test SITE=/path/to/wordpress` | Copy what ships into a real network's plugins folder, and the `.mo` into `wp-content/languages/plugins/`. |
| `make review-local` / `make pre-pr` | The organisation's review before the pull request; everything a pull request is checked on, then that review. |
| `make clean` | Caches and build artefacts. |

## The repository name is not the plugin slug

The slug and text domain are **`wpm-user-sync`**, permanent since the plugin
is published. The repository folder may be named otherwise (the move into
the DiluxOne organisation may rename it), and wp-env mounts the plugin under
the folder's name. So every tool is told which one it needs:

| Needs the **slug** | How |
| --- | --- |
| `.github/workflows/pull-request.yml`, `release.yml` | `slug: wpm-user-sync`, `main-file: wpm-user-sync.php`. |
| `Makefile` (`dist`, `zip`, `plugin-check`, `i18n`, `deploy-test`) | `SLUG := wpm-user-sync`. |

| Needs the **checkout's folder name** | How |
| --- | --- |
| `Makefile` (`test-integration`) | `REPO_DIR := $(notdir $(CURDIR))`. |
| The end-to-end suite (`wp plugin …` on the dev network) | `PLUGIN_DIR`, the folder Playwright runs from. |
| The shared `plugin-tests-wp` workflow | The repository's name, which is the checkout's folder in CI. |

The PHP test suites locate the plugin from their own path, never from
`wp-content/plugins/<name>`.

### Why the bundled `.mo` files need copying

The plugin does not call `load_plugin_textdomain()` (Plugin Check discourages
it), and without that call WordPress reads plugin translations only from
`wp-content/languages/plugins/`, where wordpress.org installs the language
packs. `languages/*.mo` inside the plugin folder is inert; `make deploy-test`
puts it where WordPress reads it.

## Configuration

`.wp-env.json`: latest WordPress, PHP 8.5, the checkout as a plugin, the e2e
mu-plugin, `WP_DEBUG` and `WP_DEBUG_LOG` on (errors go to
`wp-content/debug.log`), ports 8898/8899. Override locally in a git-ignored
`.wp-env.override.json`.

Docker images: `COMPOSER_IMAGE` (`composer:2`), `WP_CLI_IMAGE`
(`wordpress:cli`), `PHP_IMAGE` (`php:8.3-cli`, for Psalm 5); pin any of them
on the command line, e.g. `make stan COMPOSER_IMAGE=composer:2.8`.

## Manual install

Clone the repository into `wp-content/plugins/wpm-user-sync/` of any
WordPress network and network-activate it; there is no build step. The
static gates and the unit suite work the same without wp-env.
