# Contributing

Thank you for contributing to Yopass Bundle.


## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](../CODE_OF_CONDUCT.md). By participating, you are expected to uphold it. Please report unacceptable behavior to **hectorfranco@nowo.tech**.

## Development setup

```bash
make up
make install
make assets
make test
make test-ts
```

## Quality checks

| Command | Scope |
|---------|--------|
| `make qa` | PHP-CS-Fixer + PHPUnit |
| `make phpstan` | Static analysis (level 8) |
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
