# Contributing to DiluxOne Multisite User Sync

Thanks for helping. This page covers issues, pull requests and what CI
enforces. The organisation's [contributing guide](https://github.com/DiluxOne/.github/blob/main/CONTRIBUTING.md)
has the general rules; this one adds what is specific to the plugin.

## Bugs, ideas and questions

- **A bug or a feature request:** open a new issue with the matching
  template. Claude reads it first, labels it and replies once (it may ask for
  versions or steps, or try to reproduce a bug with a unit test); the
  maintainer decides what happens next. What is planned and what is not is in
  [`docs/roadmap.md`](docs/roadmap.md).
- **Using the plugin** (how do I…?, a user did not get a site): the
  [wordpress.org support forum](https://wordpress.org/support/plugin/wpm-user-sync/),
  where answers stay public for the next person.
- **A security vulnerability:** [SECURITY.md](SECURITY.md), never a public
  issue.

## Pull requests

1. Branch from `main` (your fork, if you are an outside contributor), named
   `<type>/<kebab-case>`, for example `fix/role-sync-super-admins`.
2. Make the change with its tests at every layer it touches (unit,
   integration on a network, end-to-end, and the layout run, the baselines
   and the listing screenshots when a screen changes; see
   [`docs/testing-and-quality.md`](docs/testing-and-quality.md)), add its row
   to [`tests/e2e/COVERAGE.md`](tests/e2e/COVERAGE.md), and update any doc
   that describes what you changed. A change a user notices adds one bullet to
   the newest `= X.Y.Z =` entry of `readme.txt`, under its `Unreleased.` line;
   leave that line alone ([`docs/release.md`](docs/release.md)).
3. Run `make pre-pr` (needs `make env`): `make check` (PHPCS, PHPStan, Psalm,
   unit tests), the unit tests on PHP 8.0, the translations check, the docs
   check, `make coverage` (the unit, integration and end-to-end suites, the
   single-site check included, with their coverage floors), Plugin Check, and
   the local review (`make review-local`), which checks the branch, the title, the
   commits and, with `REVIEW_ARGS="--body-file build/pr.md"`, the description
   as CI will, and runs the same Claude review through the Claude Code CLI on
   your own account. It clones the organisation's shared scripts from
   [DiluxOne/.github](https://github.com/DiluxOne/.github) into `build/`.
4. Open the pull request and fill in the template: 📝 What changes and 💡 Why
   are required. The description becomes the commit body on `main`, word for
   word: plain words, short paragraphs.
5. If AI took part, end the description with one line:
   `🤖 AI-assisted · <model> (<maker>)`. The rules are in
   [`docs/ai.md`](docs/ai.md).

Pull requests are squash-merged: the title becomes the commit title on
`main`, the description its body, and `Co-authored-by` trailers are kept.
Nothing reaches `main` without a green pull request, maintainers included.

### Titles and commits

[Conventional Commits](https://www.conventionalcommits.org/):
`<type>(<optional-scope>): <subject>`, with type one of `feat`, `fix`,
`docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`,
`revert`, at most 100 characters, no trailing period, for the pull request
title and every commit. Bodies are plain paragraphs, one line each, never
hard-wrapped.

```
fix(sync): leave super admins alone when a role changes
feat(sync): let a filter keep a site out of every automatic sync
```

### What CI enforces

Once the repository is in the DiluxOne organisation (until then, the Make
targets are the gates):

- The shared [`conventions`](https://github.com/DiluxOne/.github/blob/main/.github/workflows/conventions.yml)
  workflow: branch name, title and every commit in the format above; no
  `Claude-Session:` trailer; "📝 What changes" and "💡 Why" filled; no
  "Generated with …" footer; relative doc links that resolve.
- The quality gates: syntax and unit tests on PHP 8.0 to 8.5, PHPCS with the
  WordPress Coding Standards, PHPStan level 8, Psalm taint analysis, i18n
  extraction, Plugin Check on the shipped tree, readme and version markers,
  integration tests on a wp-env network and the Playwright suite on a wp-env
  network.
- The Claude review, guided by [`docs/architecture.md`](docs/architecture.md),
  [`AGENTS.md`](AGENTS.md) and the WordPress review profile: inline comments
  on blockers and majors, `risk:*`, `complexity:*` and `type:*` labels. Answer
  in the thread mentioning `@dilux-bot`; every conversation must be resolved
  before merging. Forks are not reviewed automatically; the maintainer
  reviews them.

## Coding rules the linters cannot express

- **PHP 8.0 and WordPress 6.6** are the minimums; the plugin needs Multisite.
- **No Composer dependencies at runtime.** `vendor/` never ships.
- **Every membership write goes through the sync engine**, with its guards:
  live sites of this network only, never a super admin, the destination's
  default role, removals respected, the re-entrancy flag.
- **Capability and nonce before anything else** in every handler.
- **Published names are permanent**: slug, text domain, options, meta keys,
  hooks, filters, page slugs, the 1.4 functions.
- **Every user-facing string** translatable with the `wpm-user-sync` domain,
  with a `/* translators: */` comment right before a placeholder; after
  changing strings, every locale updated (`make i18n-update`, translate,
  `make i18n-mo`, `make i18n-check`).
- **Input unslashed and sanitized, output escaped, SQL prepared.**

The full list, with the architecture and the review priorities:
[`docs/architecture.md`](docs/architecture.md).

## Versions and releases

Nobody types a version: it is computed from the `type:*` labels of what
merged, and `main`'s markers say the last version released (1.5.0). The
changelog is written as the changes merge, under the `Unreleased.` line of
the newest `readme.txt` entry; the maintainer removes that line when the
version is ready and approves the deployment. Never bump the version or
remove that line in your pull request. The whole flow:
[`docs/release.md`](docs/release.md).

## Code of Conduct and licence

By participating you agree to the organisation's
[Code of Conduct](https://github.com/DiluxOne/.github/blob/main/CODE_OF_CONDUCT.md).
Your contributions are licensed under the [GPL-2.0-or-later](LICENSE).
