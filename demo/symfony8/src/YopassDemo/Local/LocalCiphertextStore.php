<?php

declare(strict_types=1);

namespace App\YopassDemo\Local;

use RuntimeException;

use function base64_decode;
use function chmod;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_readable;
use function mkdir;
use function preg_match;
use function random_bytes;
use function sodium_crypto_secretbox;
use function sodium_crypto_secretbox_open;
use function sprintf;
use function strlen;
use function substr;

use const LOCK_EX;
use const SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
use const SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

/**
 * Stores E2E ciphertext on the local filesystem (outside the web root).
 *
 * Optional at-rest encryption via YOPASS_LOCAL_STORAGE_KEY (base64, 32 bytes).
 */
final class LocalCiphertextStore
{
    public const REF_PREFIX = 'yopass+local:';

    public function __construct(
        private readonly string $storageDir,
        private readonly ?string $encryptionKeyBase64 = null,
    ) {
        $this->ensureStorageDir();
    }

    public function isReference(string $value): bool
    {
        return str_starts_with($value, self::REF_PREFIX);
    }

    public function upload(string $shareId, string $ciphertext): string
    {
        $filename = $this->safeFilename($shareId) . '.enc';
        $path     = $this->storageDir . '/' . $filename;

        if (file_put_contents($path, $this->protect($ciphertext), LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Cannot write ciphertext file "%s".', $path));
        }

        chmod($path, 0600);

        return self::REF_PREFIX . $filename;
    }

    public function download(string $reference): string
    {
        if (!$this->isReference($reference)) {
            throw new RuntimeException('Not a local ciphertext reference.');
        }

        $filename = substr($reference, strlen(self::REF_PREFIX));
        $this->assertSafeFilename($filename);

        $path = $this->storageDir . '/' . $filename;

        if (!is_readable($path)) {
            throw new RuntimeException('Ciphertext file not found.');
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Cannot read ciphertext file.');
        }

        return $this->unprotect($contents);
    }

    private function ensureStorageDir(): void
    {
        if (is_dir($this->storageDir)) {
            return;
        }

        if (!mkdir($this->storageDir, 0700, true) && !is_dir($this->storageDir)) {
            throw new RuntimeException(sprintf('Cannot create storage directory "%s".', $this->storageDir));
        }
    }

    private function resolveKey(): ?string
    {
        if ($this->encryptionKeyBase64 === null || $this->encryptionKeyBase64 === '') {
            return null;
        }

        $key = base64_decode($this->encryptionKeyBase64, true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('YOPASS_LOCAL_STORAGE_KEY must be base64-encoded 32 bytes.');
        }

        return $key;
    }

    private function protect(string $plaintext): string
    {
        $key = $this->resolveKey();

        if ($key === null) {
            return $plaintext;
        }

        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);

        return $nonce . $cipher;
    }

    private function unprotect(string $payload): string
    {
        $key = $this->resolveKey();

        if ($key === null) {
            return $payload;
        }

        $nonce  = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain  = sodium_crypto_secretbox_open($cipher, $nonce, $key);

        if ($plain === false) {
            throw new RuntimeException('Failed to decrypt stored ciphertext.');
        }

        return $plain;
    }

    private function safeFilename(string $shareId): string
    {
        $this->assertSafeFilename($shareId);

        return $shareId;
    }

    private function assertSafeFilename(string $filename): void
    {
        if ($filename === '' || preg_match('/^[a-zA-Z0-9_-]+$/', $filename) !== 1) {
            throw new RuntimeException('Invalid storage filename.');
        }
    }
}
