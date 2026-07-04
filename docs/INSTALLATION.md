# Installation

## Requirements

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

See [Configuration](CONFIGURATION.md) for custom `YopassAccessCheckerInterface`.

## Assets

Install bundle public assets:

```bash
php bin/console assets:install
```

Templates load `asset('js/yopass.js', 'nowo_yopass')` — rebuild with `pnpm run build` in the bundle repo if you fork it.

## Demo

See [demo/README.md](../demo/README.md) and [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md).
