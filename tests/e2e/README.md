# End-to-end tests

A real browser (Playwright, Chromium) against a real WordPress network. The
plugin is a network plugin, so the suite runs where it lives: the wp-env
**dev site is a subdirectory network** (`.wp-env.json`, `"multisite": true`,
<http://localhost:8898>). One check needs the opposite, a WordPress that is
*not* a network, and runs on the Plugin Check environment's tests site
(<http://localhost:8901>), with the built plugin mounted.

## What has to be up

```bash
make install     # composer, npm, and Playwright's Chromium (once)
make env         # the dev network (8898) and the tests network (8899)
make test-e2e    # the network suite, then the single-site check
```

`make test-e2e-single` starts the Plugin Check environment itself (`make
dist` first) and runs only the single-site check.

## How it is built

| Piece | What it does |
| --- | --- |
| [`playwright.config.ts`](../../playwright.config.ts) | Projects: `setup`, `chromium` and `teardown` always; `single`, `visual` and `listing` only when their variable is set. |
| [`global.setup.ts`](global.setup.ts) / [`global.teardown.ts`](global.teardown.ts) | Write down the three toggles and the queue, turn the toggles off, sweep what an interrupted run left, sign in as the super admin; the teardown deletes everything the run made and puts the rest back. The dev network is somebody's: nothing else is touched. |
| [`support/env.ts`](support/env.ts) | The URLs, read from `.wp-env.json` (never a hardcoded port) and the environment. |
| [`support/cli.ts`](support/cli.ts) | WP-CLI in the wp-env container: `docker exec` into this checkout's container when it can find it, `npx wp-env run` otherwise. |
| [`support/network.ts`](support/network.ts) | Builds the cases (sites with a default role, archived or spam sites, users with memberships, super admins) and reads what the plugin did (memberships, the queue, the removal record). Everything is named `e2e-…`. |
| [`support/ui.ts`](support/ui.ts) | The plugin's screens by page slug, their forms, signing in as somebody else. |
| [`support/screens.ts`](support/screens.ts) | The registry of every screen and tab. The layout spec fails when the menu or a tab strip has one that is not listed. |
| [`support/layout.ts`](support/layout.ts) | The layout invariants: overlap, overflow, blank boxes, hidden elements still drawn, sideways scroll, at 1600/1280/960/782 px. |
| [`mu-plugin/wpmus-e2e.php`](mu-plugin/wpmus-e2e.php) | Test-only; its folder is mapped as mu-plugins by `.wp-env.json` and the Plugin Check environment: turns the plugin's public filters from the network option `wpmus_e2e_knobs` (inline limit, batch size, time limit, excluded sites, replicating administrator), can hold WP-Cron for web requests so a queued sync can be looked at before it moves, and can pause the first background batch so a test changes the queue while a run works on it. |
| [`mu-plugin/wpmus-coverage.php`](mu-plugin/wpmus-coverage.php) | Test-only: while `make coverage` runs, records the plugin lines each request executes into `build/coverage/e2e/`. Does nothing otherwise. |

Tests act as a person does: the forms, the menus, the buttons. WP-CLI only
sets a case up and reads the result, and runs WP-Cron's event when a test
moves a background sync on one run at a time.

## The pictures

- `make test-visual` compares every screen with the baseline in
  [`snapshots/`](snapshots/); `make test-visual-update` retakes them. A
  baseline belongs to the machine that took it (fonts, smoothing), so neither
  `make test-e2e` nor CI compares them. Read the diff before committing it.
- `make screenshots` retakes the wordpress.org listing screenshots,
  `.wordpress-org/screenshot-1..6.png`, from the real screens; the captions
  under `== Screenshots ==` in `readme.txt` are what they answer to.

## Which test covers what

[`COVERAGE.md`](COVERAGE.md).
