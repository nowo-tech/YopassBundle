# Local file storage (demo default)

The Symfony 8 demo ships with **local file shares** enabled out of the box. File ciphertext is stored under `var/yopass-storage/` (gitignored, outside the web root). Text shares stay in the database.

## Configuration

Set in `demo/symfony8/.env` (see `.env.example`):

| Variable | Default | Description |
|----------|---------|-------------|
| `YOPASS_LOCAL_STORAGE_DIR` | `%kernel.project_dir%/var/yopass-storage` | Directory for ciphertext files (not public) |
| `YOPASS_LOCAL_MAX_FILE_BYTES` | `524288` (512 KiB) | Max raw file size before client encryption |
| `YOPASS_LOCAL_STORAGE_KEY` | *(empty)* | Optional base64 32-byte key for at-rest encryption on disk |

Generate an at-rest key:

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

When `YOPASS_LOCAL_STORAGE_KEY` is set, blobs on disk are encrypted with libsodium `secretbox` in addition to browser E2E encryption.

## Wiring

Committed in the demo:

- `src/YopassDemo/Local/` — store, repository decorator, file handler
- `config/packages/nowo_yopass_files.yaml` — `file_handler` + custom repository
- `config/services_files.yaml` — service arguments from env

The storage directory is created on `make up` with mode `0700`; files are written as `0600`.

## Optional: AWS S3

For S3 instead of local disk, run `make scaffold-s3-examples`, set `YOPASS_USE_S3=1`, and follow [S3.md](S3.md). The gitignored S3 config overrides local storage only when that env var is set.

## Production

Copy `YopassDemo/Local` into your app namespace, point `nowo_yopass.file_handler` and `database.repository` at the local services, and keep the storage path outside `public/`.

Since **1.2.0**, custom repository decorators must also implement `consumeReadIfAvailable()` — delegate to the inner repository and hydrate offloaded ciphertext (see `LocalOffloadingShareRepository` in the demo).
