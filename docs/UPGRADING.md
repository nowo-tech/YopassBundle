# Upgrading

This document describes how to upgrade between versions of Yopass Bundle.

## 1.0.1 (2026-07-04)

Patch release. No application code changes required.

### Symfony 8 + Doctrine

If you run Symfony 8, ensure **`doctrine/doctrine-bundle` ^3.0** is installed. Version 2.x does not support Symfony 8:

```bash
composer require doctrine/doctrine-bundle:^3.0
```

The bundle already allows `^2.10 || ^3.0` in `composer.json`; Composer resolves the correct major for your Symfony version.

### Assets

After upgrading, reinstall public assets if you vendor-copy them:

```bash
php bin/console assets:install
```

## 1.0.0 (2026-07-04)

First stable release. No upgrade steps when installing for the first time.

### Requirements

- **PHP:** >= 8.2, < 8.6 with `ext-sodium`
- **Symfony:** ^7.4 || ^8.0
- **Doctrine Bundle:** ^2.10 (Symfony 7.x) or ^3.0 (Symfony 8.x)
- **Doctrine ORM:** ^2.15 || ^3.0 (unless using MongoDB ODM or a custom repository)

### Install

```bash
composer require nowo-tech/yopass-bundle
php bin/console assets:install
php bin/console doctrine:schema:update --force
# or create a migration
```

Configure `user_class`, security `access_control` for `/share` (public) and manage routes (authenticated). See [INSTALLATION.md](INSTALLATION.md).

### Public links

One-click links use the query parameter **`decrypt_key`**:

```
/share/{uuid}?decrypt_key=BASE64URL_KEY
```

Short links omit the parameter; recipients paste the key in the reveal page. Legacy `#fragment` and `?key=` URLs are still read by the browser bundle but are no longer generated.

## Unreleased / 1.x

Breaking or notable changes in future 1.x releases will be documented here.
