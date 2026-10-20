# Contributing

Thank you for contributing to Yopass Bundle.


## Table of contents

- [Code of Conduct](#code-of-conduct)
- [Development setup](#development-setup)
  - [Git commit hygiene (REQ-GIT-001)](#git-commit-hygiene-req-git-001)
- [Quality checks](#quality-checks)
- [Pull requests](#pull-requests)
- [Documentation](#documentation)
- [Code style](#code-style)
- [License](#license)
- [AI co-author trailers](#ai-co-author-trailers)
- [Cursor Agent](#cursor-agent)

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](../CODE_OF_CONDUCT.md). By participating, you are expected to uphold it. Please report unacceptable behavior to **hectorfranco@nowo.tech**.

## Development setup

```bash
make up
make setup-hooks   # REQ-GIT-001: install commit-msg hook (strips Cursor co-author trailers)
make install
make assets
make test
make test-ts
```

### Git commit hygiene (REQ-GIT-001)

Cursor and other agents may inject `Co-authored-by: Cursor <cursoragent@cursor.com>` even when the assistant omits it from the message. **Run `make setup-hooks` once per clone** so `.githooks/commit-msg` is copied to `.git/hooks/` and strips those lines on every commit.

Before pushing a release commit:

```bash
make check-no-cursor-coauthor   # must pass on HEAD (run after git commit, before git push)
```

`make release-check` also runs this audit, but only **before** you commit release docs — always re-run `make check-no-cursor-coauthor` after the release commit.

If CI fails because trailers are already on the remote, see [GITHUB_CI.md](GITHUB_CI.md) (REQ-GIT-001) and run `make strip-cursor-coauthor-from-history` before `git push --force-with-lease`.

## Quality checks

| Command | Scope |
|---------|--------|
| `make qa` | PHP-CS-Fixer + PHPUnit |
| `make phpstan`, `make igor` | Static analysis (level 8) |
| `make test-ts` | Vitest (crypto / TypeScript) |
| `make release-check` | Full pre-release pipeline (composer sync, cs, rector-dry, phpstan, coverage, demos, Vitest) |

Run `make release-check` before tagging a release.

## Pull requests

1. Fork and branch from `main`.
2. Add or update tests for behaviour changes.
3. Run `make cs-fix` and `make test` before opening the PR.
4. Update `docs/CHANGELOG.md` under `[Unreleased]`.
5. Use the PR template and link related issues.

## Documentation

- User-facing changes: `README.md` and `docs/`.
- Breaking changes: `docs/UPGRADING.md` and a new section in `CHANGELOG.md`.

## Code style

- PHP: PSR-12 via PHP-CS-Fixer; PHPStan level from `phpstan.neon.dist`.
- TypeScript: follow existing patterns in `src/Resources/assets/src/`; run `make test-ts` after changes.

## License

By contributing, you agree that your contributions will be licensed under the MIT License.

## AI co-author trailers

Do **not** add AI co-author trailers to commit messages: neither `Co-authored-by: Cursor` / `cursoragent@cursor.com` nor `Co-Authored-By: Claude …` / `noreply@anthropic.com` (REQ-GIT-001). Run `make setup-hooks` so the `commit-msg` hook strips them.

## Cursor Agent

Contributors using Cursor Agent on this repository should enable these user-level settings (REQ-IDE-006):

1. **Submit with Ctrl + Enter** (macOS: **⌘ + Enter**) — Cursor Settings → Agents / Chat. Enter inserts a newline; Ctrl/⌘+Enter submits.
2. **Queue follow-ups** in user `settings.json`:

```json
{
  "cursor.composer.queueMessageDefaultBehavior": "queue"
}
```

Do not use `"steer"` as the default: it interrupts a running agent turn.

