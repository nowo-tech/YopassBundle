# Upgrading

This document describes how to upgrade between versions of Yopass Bundle.

## 1.0.0 (2026-07-04)

First stable release. No upgrade steps when installing for the first time.

### Requirements

- **PHP:** >= 8.2, < 8.6 with `ext-sodium`
- **Symfony:** ^7.4 || ^8.0
- **Doctrine ORM:** ^2.15 || ^3.0 (unless using MongoDB ODM or a custom repository)

### Install

```bash
composer require nowo-tech/yopass-bundle
php bin/console assets:install
php bin/console doctrine:schema:update --force
# or create a migration
```

Configure `user_class`, security `access_control` for `/share` (public) and manage routes (authenticated). See [INSTALLATION.md](INSTALLATION.md).

### Assets

Templates load `asset('js/yopass.js', 'nowo_yopass')`. After upgrading the bundle, run:

```bash
php bin/console assets:install
```

If you fork the bundle and change TypeScript sources, rebuild with `pnpm run build` in the bundle root.

### Public links

One-click links use the query parameter **`decrypt_key`**:

```
/share/{uuid}?decrypt_key=BASE64URL_KEY
```

Short links omit the parameter; recipients paste the key in the reveal page. Legacy `#fragment` and `?key=` URLs are still read by the browser bundle but are no longer generated.

## Unreleased / 1.x

Breaking or notable changes in future 1.x releases will be documented here.
