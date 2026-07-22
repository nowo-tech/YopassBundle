# Demo with FrankenPHP

The bundle includes a **Symfony 8** demo under `demo/symfony8/` using **FrankenPHP** and Docker Compose.

## Table of contents

- [Quick start (development)](#quick-start-development)
- [Production / worker mode](#production--worker-mode)
- [Demo pages](#demo-pages)
- [Commands](#commands)
- [Troubleshooting](#troubleshooting)

## Quick start (development)

From the bundle root:

```bash
make -C demo/symfony8 up
```

Default URL: **http://localhost:8021/en/** (override with `PORT` in `demo/symfony8/.env`).

In **development** (`APP_ENV=dev`), the container entrypoint copies `Caddyfile.dev`, which runs **without** FrankenPHP worker so code and Twig changes appear on refresh.

## Production / worker mode

The demo ships two Caddy configurations:

| File | Mode | Use |
|------|------|-----|
| `docker/frankenphp/Caddyfile` | **Worker** (`php_server { worker … }`) | Production-like performance |
| `docker/frankenphp/Caddyfile.dev` | **Request** (no worker) | Local development |

To run with worker mode:

```bash
cd demo/symfony8
APP_ENV=prod APP_DEBUG=0 docker-compose up -d --build
```

Or set `APP_ENV=prod` in `.env` and rebuild. The entrypoint keeps the production `Caddyfile` when `APP_ENV` is not `dev`.

**Yopass Bundle** is stateless (form rendering + validator + client script) and does not rely on per-request global state incompatible with FrankenPHP workers.

## Demo pages

| Route | Description |
|-------|-------------|
| `/en/` | Home with links to examples |
| `/en/demo/level` | `policy_mode: level` (weak / medium / strong) |
| `/en/demo/conditions` | Inline `conditions` |
| `/en/demo/plain` | Plain Symfony `PasswordType` (no strength UI) |
| `/es/...` | Spanish locale |

## Commands

```bash
make -C demo/symfony8 up          # start (install + cache + assets)
make -C demo/symfony8 down        # stop
make -C demo/symfony8 shell       # PHP container shell
make -C demo/symfony8 test        # smoke checks
make -C demo/symfony8 link-bundle # symlink local bundle (path repo)
make -C demo/symfony8 update-bundle
```

From bundle root:

```bash
make -C demo up
```

## Switching classic vs worker (`FRANKENPHP_MODE`)

Demos select the FrankenPHP runtime via **`FRANKENPHP_MODE`** in `.env` / `.env.example` (not a Dockerfile `ENV`):

| Value | Behaviour |
| --- | --- |
| **`worker`** (default) | Keep the worker Caddyfile (`php_server { worker ... }`) |
| **`classic`** | Entrypoint copies `Caddyfile.dev` (plain `php_server`, hot-reload friendly) |

Compose passes `FRANKENPHP_MODE=${FRANKENPHP_MODE:-worker}` into the PHP service. After changing `.env`, run `docker compose up -d` (or `make up`) so the container is **recreated** — a plain `restart` does not reload env. No image rebuild is required.

## Troubleshooting

- **Port in use:** set `PORT=8011` (or another free port) in `demo/symfony8/.env`.
- **Stale assets:** `make -C demo/symfony8 cache-clear` and `make assets` at bundle root.
- **Password toggle:** demo installs `nowo-tech/password-toggle-bundle` via Composer (Packagist); only `yopass-bundle` uses a path repo for local development.
- **Packagist / DNS in Docker:** demo `docker-compose.yml` sets public DNS (`8.8.8.8`) for Composer inside the container.
