# Installation

## Requirements

- **FormKitBundle** (`nowo-tech/form-kit-bundle` ^2.0) — dashboard/admin Symfony forms (`FormOptionsTrait`, profile `yopass`). Register `NowoFormKitBundle` in `config/bundles.php` (Flex / demo). Optional host YAML: `config/packages/nowo_form_kit.yaml`.

- PHP 8.2+ with `ext-sodium`
- Symfony 7.4+ or 8.x
- Doctrine ORM 2.15+ or 3.x
- Doctrine Bundle 2.10+ (Symfony 7.x) or 3.0+ (Symfony 8.x)

## Composer

```bash
composer require nowo-tech/yopass-bundle
```

## Symfony Flex recipe

When using Flex, the recipe registers:

- `config/packages/nowo_yopass.yaml`
- `config/routes/nowo_yopass.yaml`

Manual install:

```php
// config/bundles.php
Nowo\YopassBundle\YopassBundle::class => ['all' => true],
```

```yaml
# config/routes/nowo_yopass.yaml
# Thin loader: imports #[Route] attributes from ShareManageController / PublicShareController
# and applies nowo_yopass.routes + route_prefix overrides (do not also attribute-import the controllers).
nowo_yopass:
    resource: .
    type: nowo_yopass
```

## Doctrine schema

Configure `user_class` and `table_prefix`, then update schema:

```bash
php bin/console doctrine:schema:update --force
# or create a migration
```

Default table name: `{table_prefix}secure_shares` (e.g. `yopass_secure_shares`).

## Security firewall

Manage routes require authentication. Public share routes must allow anonymous access:

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/share, roles: PUBLIC_ACCESS }
        - { path: ^/tools/yopass, roles: ROLE_USER }
```

See [Configuration](CONFIGURATION.md) for `YopassAccessCheckerInterface`, [Access control events](examples/AccessControl.md), and public rate limiting (`public_rate_limit`, requires `cache.app`).

## Translations

The bundle ships **EN**, **ES**, **DE**, **FR**, **IT**, **NL**, and **PT** under the `NowoYopassBundle` domain. Override in `translations/bundles/NowoYopassBundle/` or your app's `translations/` folder.

## Assets

Install bundle public assets:

```bash
php bin/console assets:install
```

Templates load `asset('js/yopass.js', 'nowo_yopass')` — rebuild with `pnpm run build` in the bundle repo if you fork it.

## Demo

See [demo/README.md](../demo/README.md) and [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md).

## Twig Extra Bundle (REQ-TWIG-004)

This package ships Twig templates. Host applications **must** install and enable Twig Extra:

```bash
composer require twig/extra-bundle twig/string-extra
```

Register `Twig\Extra\TwigExtraBundle\TwigExtraBundle` in `config/bundles.php` (Flex usually does this). Demos already include the same stack. The package `release-check` runs `make check-twig-extra` to guard this contract.
