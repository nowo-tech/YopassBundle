# Yopass Bundle

[![CI](https://github.com/nowo-tech/YopassBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/YopassBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/yopass-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/yopass-bundle) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/yopass-bundle.svg)](https://packagist.org/packages/nowo-tech/yopass-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-7.4%2B%20%7C%208.0%20%7C%208.1%2B-000000?logo=symfony)](https://symfony.com) [![GitHub stars](https://img.shields.io/github/stars/nowo-tech/YopassBundle.svg?style=social&label=Star)](https://github.com/nowo-tech/YopassBundle) [![Coverage](https://img.shields.io/badge/Coverage-100%25%20PHP%20%7C%2074.21%25%20TS-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** Give it a **star** on [GitHub](https://github.com/nowo-tech/YopassBundle) so more developers can find it.

Symfony bundle for **Yopass-style E2E encrypted secret sharing**: client-side libsodium encryption, expiration, read limits, and public reveal pages.

**FrankenPHP worker mode:** Supported — stateless controllers and services; tested with the Symfony 8 demo using FrankenPHP (see [Demo with FrankenPHP](docs/DEMO-FRANKENPHP.md)).

## Features

- Browser-side encryption (`libsodium` secretbox); server stores ciphertext only
- Text and file payloads (max 512 KB files; file tab requires `file_handler` service)
- Configurable expiration options, max-read limits, list pagination, and retention purge
- Auto-generated decryption key or custom password mode
- One-click links via `?decrypt_key=` query parameter; short links with manual key entry
- Authenticated manage UI + anonymous public reveal/consume routes
- Configurable routes, table prefix, templates, and pluggable access control

## Installation

```bash
composer require nowo-tech/yopass-bundle
```

See [Installation](docs/INSTALLATION.md) for Flex recipe, Doctrine schema, routes, and security firewall setup.

## Configuration

```yaml
# config/packages/nowo_yopass.yaml
nowo_yopass:
    user_class: App\Entity\User
    table_prefix: yopass_
    security:
        access_checker: App\Security\CustomYopassAccessChecker  # optional
```

Full reference: [Configuration](docs/CONFIGURATION.md).

## Usage

- Manage UI: `/tools/yopass` (configurable)
- Public reveal: `/share/{id}` or `/share/{id}?decrypt_key=…` (configurable)
- Override Twig: `templates/bundles/NowoYopassBundle/`

See [Usage](docs/USAGE.md).

## Demo

```bash
make -C demo up-symfony8
# Demo started at: http://localhost:8022  →  Yopass CRUD at /tools/yopass (auto-login)
```

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release process](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)

### Additional documentation

- [Demo with FrankenPHP](docs/DEMO-FRANKENPHP.md)
- [Local file storage (demo default)](docs/examples/LocalStorage.md)
- [AWS S3 file shares example (local, gitignored)](docs/examples/S3.md)

## Tests and Coverage

```bash
make test              # PHPUnit
make test-coverage     # PHP coverage + percentage script
make test-ts           # Vitest (crypto)
make release-check     # Full pre-release chain
```

| Language | Coverage |
|----------|----------|
| PHP | **100%** (Lines) — `make test-coverage-100` |
| TypeScript | **74.21%** (Lines) — `make test-ts` (password-mode paths require browser sodium runtime) |

## License

MIT — see [LICENSE](LICENSE).
