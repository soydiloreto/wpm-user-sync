# wpm-user-sync — developer task runner for DiluxOne Multisite User Sync.
#
# All PHP-based commands run inside the official `composer:2` Docker
# image by default. That keeps the host clean of plugin-specific PHP
# extensions (dom, mbstring, xml, xmlwriter, etc.) which the standard
# WSL `php-cli` build tends to lack. The Composer + dev-tooling
# versions still come from composer.lock either way, so the runtime
# difference vs CI is just the PHP-extension surface area.
#
# Usage:
#   make            # = make help
#   make install    # composer install + npm install
#   make check      # the fast gates: lint, stan, psalm, unit tests
#   make env        # the local network (http://localhost:8898)
#
# Override DOCKER=0 to invoke local binaries instead. Only viable on
# hosts that already have a full PHP CLI with the required extensions
# (dom, mbstring, xml, xmlwriter, libxml, openssl, json, fileinfo,
# tokenizer) and `wp` and `npx` available on $PATH.

SHELL := /bin/bash

# -- Docker plumbing ---------------------------------------------------
# Mount the project read/write at /app, run as the host user so
# composer doesn't leave root-owned files in vendor/.
DOCKER ?= 1
DOCKER_USER := $(shell id -u):$(shell id -g)
DOCKER_RUN  := docker run --rm -u $(DOCKER_USER) -v $(CURDIR):/app -w /app

# Pin floating tags via env override for reproducibility:
#   make stan COMPOSER_IMAGE=composer:2.7
COMPOSER_IMAGE ?= composer:2
WP_CLI_IMAGE   ?= wordpress:cli
PHP_IMAGE      ?= php:8.3-cli

ifeq ($(DOCKER),1)
COMPOSER  := $(DOCKER_RUN) $(COMPOSER_IMAGE) composer
VENDOR    := $(DOCKER_RUN) $(COMPOSER_IMAGE)
PSALM_CMD := $(DOCKER_RUN) $(PHP_IMAGE) ./vendor/bin/psalm
WP_CLI    := $(DOCKER_RUN) $(WP_CLI_IMAGE)
else
COMPOSER  := composer
VENDOR    :=
PSALM_CMD := ./vendor/bin/psalm
WP_CLI    := wp
endif

# The plugin's slug, text domain and folder on wordpress.org. The repository
# folder may be named otherwise (wp-env mounts the checkout under its own
# directory name), so everything that addresses the mounted plugin uses
# REPO_DIR and everything that addresses what ships uses SLUG.
SLUG     := wpm-user-sync
REPO_DIR := $(notdir $(CURDIR))

# The ports of this repository's stacks. 8888-8897 belong to the other
# DiluxOne plugins on the same machine (diluxone-offload 8888/8889 and
# 8896/8897, diluxone-users 8892-8895). The dev and tests ports are also in
# .wp-env.json, which is what wp-env and the test suites read.
PCP_PORT       := 8900
PCP_TESTS_PORT := 8901

# -- Default target ----------------------------------------------------
.DEFAULT_GOAL := help

.PHONY: help
help: ## Show this help.
	@awk 'BEGIN {FS = ":.*##"; printf "\nTargets:\n"} \
	  /^[a-zA-Z0-9_-]+:.*##/ {printf "  \033[1;32m%-20s\033[0m %s\n", $$1, $$2}' \
	  $(MAKEFILE_LIST)
	@echo
	@echo "Override the Docker mode with DOCKER=0 to use local binaries."

# -- Setup -------------------------------------------------------------
.PHONY: install
install: ## Install dev dependencies (composer install, npm install, Playwright's Chromium).
	$(COMPOSER) install --no-interaction --prefer-dist --no-progress
	npm install --no-audit --no-fund
	npx playwright install chromium

.PHONY: update
update: ## Update dev dependencies (composer update).
	$(COMPOSER) update --no-interaction --prefer-dist --no-progress

# -- Linting / static analysis ----------------------------------------
.PHONY: lint
lint: ## PHPCS + WordPress Coding Standards.
	# --no-cache on purpose: PHPCS caches per file, so a file reverted to
	# contents it has already seen gets the old verdict back. That reads green
	# locally while CI, which always starts cold, is red.
	$(VENDOR) ./vendor/bin/phpcs --no-cache

.PHONY: lint-fix
lint-fix: ## Auto-fix PHPCS violations where possible.
	$(VENDOR) ./vendor/bin/phpcbf --no-cache

.PHONY: stan
stan: ## PHPStan level 8 (no baseline).
	$(VENDOR) ./vendor/bin/phpstan analyse --memory-limit=2G --no-progress

.PHONY: psalm
psalm: ## Psalm taint analysis (XSS / SQLi / RCE).
	$(PSALM_CMD) --taint-analysis --no-cache --no-progress

# -- Translations ------------------------------------------------------
# The .pot ships; the eight .po/.mo are the source for translate.wordpress.org
# and what `make deploy-test` installs on a test site. WordPress reads plugin
# translations from wp-content/languages/plugins/ (the language packs), never
# from the plugin folder: there is no load_plugin_textdomain() call.
.PHONY: i18n
i18n: ## Refresh languages/wpm-user-sync.pot from the source strings.
	$(WP_CLI) i18n make-pot . languages/$(SLUG).pot \
	    --slug=$(SLUG) \
	    --domain=$(SLUG) \
	    --exclude=tests,vendor,node_modules,.wordpress-org,docs,build

.PHONY: i18n-update
i18n-update: i18n ## Merge the refreshed .pot into every shipped .po (keeps existing translations).
	@for po in languages/*.po; do \
	  $(WP_CLI) i18n update-po languages/$(SLUG).pot "$$po" >/dev/null && echo "  merged $$po"; \
	done

.PHONY: i18n-mo
i18n-mo: ## Compile every languages/*.po into the .mo WordPress actually reads.
	$(WP_CLI) i18n make-mo languages languages
	@echo "✔ $$(ls languages/*.mo | wc -l) .mo files built."

.PHONY: i18n-check
i18n-check: ## Fail if any .po is malformed, untranslated, fuzzy, or behind the .pot.
	@# Fuzzy counts as incomplete: WordPress does not show a fuzzy entry, so a
	@# locale full of them reads as English while its statistics call it
	@# translated. A .po that lacks a string of the .pot is incomplete too.
	@fail=0; \
	for po in languages/*.po; do \
	  out=$$(msgfmt --check --statistics -o /dev/null "$$po" 2>&1) || fail=1; \
	  missing=$$(msgcmp --use-untranslated "$$po" languages/$(SLUG).pot 2>&1 | grep -c 'this message is used but not defined') || true; \
	  printf "%-34s %s%s\n" "$$po" "$$out" "$$( [ "$$missing" -gt 0 ] && echo ", $$missing missing from the .po" )"; \
	  case "$$out" in *untranslated*|*fuzzy*) fail=1;; esac; \
	  [ "$$missing" -eq 0 ] || fail=1; \
	done; \
	if [ "$$fail" -ne 0 ]; then echo "✗ translations incomplete, fuzzy, stale or malformed"; exit 1; fi; \
	echo "✔ every locale complete."

# -- Tests -------------------------------------------------------------
.PHONY: test
test: test-unit ## Run the unit-test suite (default — fast, no WP needed).

.PHONY: test-unit
test-unit: ## Run only the unit-test suite (no WordPress runtime).
	$(VENDOR) ./vendor/bin/phpunit --testsuite unit

# The oldest PHP the plugin supports (Requires PHP: 8.0). `make test-unit`
# runs on the composer image's PHP, which is the newest; CI runs every version
# in between.
.PHONY: test-unit-min
test-unit-min: ## Unit suite on the oldest PHP the plugin supports (8.0).
	$(DOCKER_RUN) php:8.0-cli ./vendor/bin/phpunit --testsuite unit

# Both wp-env sites are networks (.wp-env.json, "multisite": true): the tests
# site (8899) is the one the integration suite boots.
.PHONY: test-integration
test-integration: ## Run the integration suite on the wp-env tests network (needs `make env`).
	npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/$(REPO_DIR) ./vendor/bin/phpunit -c phpunit-integration.xml

# The load test: thousands of users and tens of sites on the tests network,
# with the plugin's real batch size and time limit. It prints what the request
# that starts a sync and each background run took, and checks every
# membership at the end. Minutes, so not part of `pre-pr`.
LOAD_USERS ?= 3000
LOAD_SITES ?= 10

.PHONY: test-load
test-load: ## The load test on the tests network (LOAD_USERS, LOAD_SITES; needs `make env`).
	npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/$(REPO_DIR) env LOAD_USERS=$(LOAD_USERS) LOAD_SITES=$(LOAD_SITES) ./vendor/bin/phpunit -c phpunit-load.xml

# The end-to-end suite drives a real browser against the wp-env dev network
# (8898): network admin, a site's admin, every trigger. It needs `make env`
# and Playwright's Chromium (`make install`). The single-site check runs after
# it, on the tests site of the Plugin Check environment, which is a plain
# single site with the built plugin mounted. See tests/e2e/README.md.
.PHONY: test-e2e
test-e2e: ## Run the Playwright suite: the network (dev site), then the single-site check.
	@mkdir -p build
	npx playwright test
	$(MAKE) --no-print-directory test-e2e-single

.PHONY: test-e2e-single
test-e2e-single: pcp-env ## Only the single-site check: on a site that is not a network the plugin explains and deactivates itself.
	@mkdir -p build
	WPMUS_SINGLE_URL=http://localhost:$(PCP_TESTS_PORT) WPMUS_SINGLE_ENV=$(PCP_DIR) npx playwright test --project=single

.PHONY: test-e2e-ui
test-e2e-ui: ## The network suite in Playwright's own window, for writing and debugging one.
	@mkdir -p build
	npx playwright test --ui

# The layout invariants: the same browser, the same site, no baseline images.
# Part of `make test-e2e`; this target runs only them.
.PHONY: test-layout
test-layout: ## Only the layout measurements: overlap, overflow, blank boxes, sideways scroll.
	@mkdir -p build
	npx playwright test admin-layout

# The pictures. Their own project because a baseline image belongs to the
# machine that took it: same fonts, same smoothing, same scrollbars. Neither
# `make test-e2e` nor CI compares them.
.PHONY: test-visual
test-visual: ## Compare every screen with the picture committed beside the specs.
	@mkdir -p build
	WPMUS_SNAPSHOTS=1 npx playwright test --project=visual

# When the screen changed because you changed it. Look at what git shows you
# in tests/e2e/snapshots/ before committing: that diff is the review of the
# change, and accepting it without looking is how a bug becomes the baseline.
.PHONY: test-visual-update
test-visual-update: ## Take the pictures again and accept them as the new baseline.
	@mkdir -p build
	WPMUS_SNAPSHOTS=1 npx playwright test --project=visual --update-snapshots
	@echo "✔ Pictures rewritten. \`git diff --stat tests/e2e/snapshots\` is the change you are accepting."

# The pictures the wordpress.org listing shows. Not a comparison: it writes
# .wordpress-org/screenshot-1..5.png, and the captions under `== Screenshots ==`
# in readme.txt are what they answer to. Read the diff before committing.
.PHONY: screenshots
screenshots: ## Retake the listing screenshots from the real screens (needs `make env`).
	@mkdir -p build
	WPMUS_LISTING=1 npx playwright test --project=listing
	@echo "✔ $$(ls .wordpress-org/screenshot-*.png 2>/dev/null | wc -l) pictures in .wordpress-org/"

.PHONY: test-all
test-all: test-unit test-integration test-e2e ## Unit + integration + end-to-end.
	@echo "✔ All three test levels passed."

# -- Distribution build ------------------------------------------------
# What wordpress.org receives: the working tree minus .distignore, in a folder
# named after the slug (WordPress and Plugin Check derive the text domain from
# the folder name). The repository folder may be named otherwise.
DIST_DIR := build/$(SLUG)

.PHONY: dist
dist: ## Build build/wpm-user-sync/ — exactly what gets published.
	@mkdir -p "$(DIST_DIR)"
	@# --delete, never `rm -rf` the directory itself: wp-env bind-mounts it, and
	@# replacing the inode leaves the container looking at a mount that is gone.
	@# --delete-excluded too: a file that became excluded must leave the dist.
	@rsync -a --delete --delete-excluded --exclude-from=.distignore --exclude='build' ./ "$(DIST_DIR)/"
	@# What ships is what is committed: a file git never saw (a local .env, a
	@# scratch script) fails the build instead of reaching wordpress.org.
	@untracked="$$(cd "$(DIST_DIR)" && find . \( -type f -o -type l \) | sed 's|^\./||' | while read -r f; do git -C "$(CURDIR)" ls-files --error-unmatch "$$f" >/dev/null 2>&1 || echo "$$f"; done)"; \
	if [ -n "$$untracked" ]; then echo "✖ Files in the dist that are not tracked by git:"; echo "$$untracked" | sed 's/^/    /'; exit 1; fi
	@if [ -d "$(DIST_DIR)/vendor" ] || [ -d "$(DIST_DIR)/tests" ] || [ -d "$(DIST_DIR)/node_modules" ]; then echo "✖ vendor/, tests/ or node_modules/ in the dist"; exit 1; fi
	@echo "✔ Built $(DIST_DIR) ($$(find "$(DIST_DIR)" -type f | wc -l) files)"

.PHONY: zip
zip: dist ## Package build/wpm-user-sync.zip, the plugin folder as wordpress.org serves it.
	@cd build && rm -f $(SLUG).zip && zip -qr $(SLUG).zip $(SLUG)
	@echo "✔ build/$(SLUG).zip ($$(du -h build/$(SLUG).zip | cut -f1))"

# -- Plugin Check (wordpress.org review gate) --------------------------
# The tool the plugin review team runs. PHPCS/WPCS overlaps with it but does
# not replace it: Plugin Check also enforces readme.txt structure, plugin
# headers, i18n and directory rules that WPCS knows nothing about.
#
# It runs in its own throwaway wp-env project under build/pcp, a plain single
# site on ports 8900/8901, mounting only the built dist. It cannot share the
# main environment: the plugin folder there is the repository's, and Plugin
# Check compares the text domain with the folder name; and wp-env activates
# every plugin it mounts, so mounting the repository and the dist together
# loads the plugin twice. Its tests site is also where the single-site
# end-to-end check runs.
PCP_DIR := $(CURDIR)/build/pcp

.PHONY: pcp-env
pcp-env: dist
	@mkdir -p "$(PCP_DIR)"
	@printf '%s\n' \
	  '{' \
	  '  "core": null,' \
	  '  "phpVersion": "8.5",' \
	  '  "plugins": [ "../$(SLUG)" ],' \
	  '  "mappings": { "wp-content/mu-plugins": "$(CURDIR)/tests/e2e/mu-plugin" },' \
	  '  "port": $(PCP_PORT),' \
	  '  "testsPort": $(PCP_TESTS_PORT)' \
	  '}' > "$(PCP_DIR)/.wp-env.json"
	@cd "$(PCP_DIR)" && npx @wordpress/env start >/dev/null
	@cd "$(PCP_DIR)" && (npx @wordpress/env run cli wp plugin is-installed plugin-check >/dev/null 2>&1 \
	  || npx @wordpress/env run cli wp plugin install plugin-check --activate >/dev/null)

.PHONY: plugin-check
plugin-check: pcp-env ## Run wordpress.org's Plugin Check on the built dist (an ERROR fails; warnings are listed).
	@# `wp plugin check` exits 0 whatever it finds, so the verdict is read from
	@# its table: any ERROR row fails the target.
	@cd "$(PCP_DIR)" && out="$$(npx @wordpress/env run cli wp plugin check $(SLUG) --format=table --severity=5 2>&1)"; status=$$?; \
	  echo "$$out"; [ $$status -eq 0 ] || exit $$status; \
	  if printf '%s\n' "$$out" | grep -qP '\tERROR\t'; then echo "✖ Plugin Check reported errors"; exit 1; fi; \
	  echo "✔ No Plugin Check errors."

.PHONY: plugin-check-all
plugin-check-all: pcp-env ## Plugin Check on the built dist, including warnings and notices.
	@cd "$(PCP_DIR)" && npx @wordpress/env run cli wp plugin check $(SLUG) --format=table

.PHONY: plugin-check-down
plugin-check-down: ## Stop the Plugin Check environment.
	@[ -f "$(PCP_DIR)/.wp-env.json" ] && cd "$(PCP_DIR)" && npx @wordpress/env stop 2>/dev/null || true

# -- Coverage ----------------------------------------------------------
# Line coverage of everything that ships (src/, the main file, uninstall.php,
# legacy-deprecated.php), per layer and all together, on the
# wp-env network with Xdebug in coverage mode (`make coverage` restarts it that
# way, and both environments without Xdebug again when it is done). The end-to-end layer is
# recorded per request by tests/e2e/mu-plugin/wpmus-coverage.php. COVERAGE_MIN is the
# floor for all layers together, COVERAGE_LAYER_MIN for each layer; below
# either, `make coverage` fails. See docs/testing-and-quality.md.
COVERAGE_MIN       ?= 100
COVERAGE_LAYER_MIN ?= 90
COVERAGE_SHOW      ?= all
TESTS_CLI := npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/$(REPO_DIR)

.PHONY: coverage
coverage: ## Line coverage of every layer and all together; fails below COVERAGE_MIN / COVERAGE_LAYER_MIN.
	npx @wordpress/env start --xdebug=coverage
	@$(MAKE) --no-print-directory coverage-unit coverage-integration coverage-e2e coverage-e2e-single \
	  && $(MAKE) --no-print-directory coverage-report; status=$$?; \
	  npx @wordpress/env start >/dev/null; exit $$status

.PHONY: coverage-unit
coverage-unit: ## Unit suite with line coverage (needs `make env` with Xdebug: see `coverage`).
	@mkdir -p build/coverage && rm -f build/coverage/unit.cov
	$(TESTS_CLI) env XDEBUG_MODE=coverage ./vendor/bin/phpunit --testsuite unit --cache-result-file=/tmp/wpmus-unit.cache --coverage-php build/coverage/unit.cov

.PHONY: coverage-integration
coverage-integration: ## Integration suite with line coverage.
	@mkdir -p build/coverage && rm -f build/coverage/integration.cov
	$(TESTS_CLI) env XDEBUG_MODE=coverage ./vendor/bin/phpunit -c phpunit-integration.xml --coverage-filter src --coverage-filter wpm-user-sync.php --coverage-filter uninstall.php --coverage-filter legacy-deprecated.php --coverage-php build/coverage/integration.cov

.PHONY: coverage-e2e
coverage-e2e: ## End-to-end network suite with line coverage, recorded per request.
	@rm -rf build/coverage/e2e && mkdir -p build/coverage/e2e && chmod 777 build/coverage/e2e
	npx playwright test

# The single-site check runs on the Plugin Check environment, where the
# plugin's folder is the dist: the collector writes into the dist's own
# build/coverage/e2e, and its files join the network suite's afterwards.
.PHONY: coverage-e2e-single
coverage-e2e-single: pcp-env ## The single-site check with line coverage (restarts the Plugin Check environment with Xdebug).
	@cd "$(PCP_DIR)" && npx @wordpress/env start --xdebug=coverage >/dev/null
	@rm -rf "$(DIST_DIR)/build" && mkdir -p "$(DIST_DIR)/build/coverage/e2e" build/coverage/e2e && chmod 777 "$(DIST_DIR)/build/coverage/e2e"
	WPMUS_SINGLE_URL=http://localhost:$(PCP_TESTS_PORT) WPMUS_SINGLE_ENV=$(PCP_DIR) npx playwright test --project=single; status=$$?; \
	  find "$(DIST_DIR)/build/coverage/e2e" -name '*.json' -exec mv {} build/coverage/e2e/ \; ; rm -rf "$(DIST_DIR)/build"; \
	  cd "$(PCP_DIR)" && npx @wordpress/env start >/dev/null; exit $$status

.PHONY: coverage-report
coverage-report: ## The coverage table from build/coverage; COVERAGE_SHOW=unit|integration|e2e lists that layer's missing lines.
	$(TESTS_CLI) php tests/coverage/report.php $(COVERAGE_MIN) $(COVERAGE_LAYER_MIN) $(COVERAGE_SHOW)

# -- Aggregate ---------------------------------------------------------
.PHONY: check
check: lint stan psalm test ## Run the fast quality gates (lint, stan, psalm, unit tests).
	@echo "✔ All checks passed."

# The organisation's local review (DiluxOne/.github, scripts/local-review.sh):
# the pull request's conventions, risk floor and Claude review, run before the
# pull request exists. The script is cloned into build/.dx-central at the
# moving tag REVIEW_CENTRAL_REF (v2, what CI calls too) and refreshed on every
# run. REVIEW_CENTRAL=<path> uses a checkout of your own instead.
REVIEW_CENTRAL     ?= build/.dx-central
REVIEW_CENTRAL_REF ?= v2

.PHONY: review-local
review-local: ## The pull request's review before it exists: conventions, risk floor, Claude review (REVIEW_ARGS="--body-file pr.md", "--title …", "--no-claude").
	@if [ "$(REVIEW_CENTRAL)" = build/.dx-central ]; then \
	  [ -d build/.dx-central/.git ] || git clone -q --depth 1 --branch $(REVIEW_CENTRAL_REF) https://github.com/DiluxOne/.github build/.dx-central; \
	  git -C build/.dx-central fetch -q --depth 1 origin $(REVIEW_CENTRAL_REF) && git -C build/.dx-central checkout -q FETCH_HEAD; \
	fi
	bash "$(REVIEW_CENTRAL)/scripts/local-review.sh" $(REVIEW_ARGS)

.PHONY: pre-pr
pre-pr: ## Everything a pull request is checked on, one after the other, then the local review (needs `make env`).
	$(MAKE) check
	$(MAKE) test-unit-min
	$(MAKE) i18n-check
	$(MAKE) docs-check
	$(MAKE) coverage
	$(MAKE) plugin-check
	$(MAKE) review-local
	@echo "✔ Checks passed; the review above says whether the branch is ready for a pull request."

# The docs job of the conventions workflow, the same checks locally: relative
# links in every Markdown file resolve (lychee, the version CI runs), and no
# retired product name is back (the regex pull-request.yml passes, when it
# names any).
LYCHEE_IMAGE ?= lycheeverse/lychee:0.24.2

.PHONY: docs-check
docs-check: ## Relative links in every Markdown file resolve, and no retired product name is back (what CI's docs job checks).
	docker run --rm -v "$(CURDIR)":/input -w /input $(LYCHEE_IMAGE) --offline --no-progress --exclude-path node_modules --exclude-path vendor --exclude-path build './**/*.md' './.github/**/*.md'
	@retired=$$(sed -n "s/.*retired-names: '\(.*\)'.*/\1/p" .github/workflows/pull-request.yml | head -1); \
	if [ -z "$$retired" ]; then echo "No retired product names configured."; exit 0; fi; \
	if git grep -nIiE "$$retired" -- . | grep -vE '^[^:]+:[0-9]+:\s*retired-names:'; then echo "A retired product name is back (see above)."; exit 1; fi; \
	echo "No retired product names."

# -- Local dev environment (wp-env, a subdirectory network) ------------
# .wp-env.json declares "multisite": true, so both sites are subdirectory
# networks: the dev network at http://localhost:8898 (network admin at
# /wp-admin/network/, admin / password) and the tests network at :8899.
.PHONY: env env-up
env: env-up ## Alias of env-up.
env-up: ## Start the local wp-env stack (dev network :8898, tests network :8899).
	npx @wordpress/env start

.PHONY: env-down
env-down: plugin-check-down ## Stop the local wp-env stack and the Plugin Check one.
	npx @wordpress/env stop

.PHONY: env-clean
env-clean: ## Destroy the local wp-env stack and its volumes.
	npx @wordpress/env destroy

# -- Deploy ------------------------------------------------------------
# Try the plugin on a real network: `make deploy-test SITE=/path/to/wordpress`
# copies only what ships (no vendor/, no tests, no tooling) into that site's
# plugins directory and leaves the site's own files alone.
SITE ?=
SITE_PLUGIN = $(SITE)/wp-content/plugins/$(SLUG)
SITE_LANGS  = $(SITE)/wp-content/languages/plugins

.PHONY: deploy-test
deploy-test: ## Copy what ships into a real site for manual smoke-testing (SITE=/path/to/wordpress).
	@if [ -z "$(SITE)" ] || [ ! -d "$(SITE)/wp-content/plugins" ]; then \
	  echo "no site at '$(SITE)'. Usage: make deploy-test SITE=/path/to/wordpress"; \
	  exit 1; \
	fi
	@mkdir -p "$(SITE_PLUGIN)"
	rsync -a --delete --delete-excluded \
	  --exclude-from=.distignore \
	  --exclude='.git' --exclude='build' \
	  ./ "$(SITE_PLUGIN)/"
	@# The bundled .mo files are inert inside the plugin folder: WordPress
	@# reads plugin translations from wp-content/languages/plugins/, where
	@# wordpress.org's language packs go. This puts them there by hand.
	@mkdir -p "$(SITE_LANGS)"
	@cp languages/*.mo "$(SITE_LANGS)/" 2>/dev/null || true
	@echo "✔ Copied to $(SITE_PLUGIN) (+ $$(ls languages/*.mo 2>/dev/null | wc -l) locales in $(SITE_LANGS))"

# -- Cleanup -----------------------------------------------------------
.PHONY: clean
clean: plugin-check-down ## Remove caches, build artefacts, and temporary files.
	rm -rf build .phpunit.result.cache .phpunit-integration.result.cache .phpunit.cache .phpcs-cache .phpstan .psalm
	@echo "✔ Cleaned."
