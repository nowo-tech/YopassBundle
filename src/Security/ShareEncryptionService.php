<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Security;

use RuntimeException;

use const JSON_THROW_ON_ERROR;
use const SODIUM_BASE64_VARIANT_ORIGINAL;
use const SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING;
use const SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
use const SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

/**
 * Builds ciphertext envelopes for tests (mirrors browser libsodium secretbox format).
 */
final class ShareEncryptionService
{
    /**
     * @return array{ciphertext: string, key: string}
     */
    public function encryptPayload(string $jsonPayload): array
    {
        $key    = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($jsonPayload, $nonce, $key);
        $box    = sodium_bin2base64($nonce . $cipher, SODIUM_BASE64_VARIANT_ORIGINAL);

        $ciphertext = json_encode([
            'v'    => 1,
            'mode' => 'key',
            'box'  => $box,
        ], JSON_THROW_ON_ERROR);

        return [
            'ciphertext' => $ciphertext,
            'key'        => sodium_bin2base64($key, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
        ];
    }

    public function decryptEnvelope(string $ciphertext, string $urlSafeKey): string
    {
        /** @var array{v?: int, mode?: string, box?: string} $envelope */
        $envelope = json_decode($ciphertext, true, 512, JSON_THROW_ON_ERROR);
        $box      = $envelope['box'] ?? $ciphertext;

        $key     = sodium_base642bin($urlSafeKey, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $decoded = sodium_base642bin($box, SODIUM_BASE64_VARIANT_ORIGINAL);
        $nonce   = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher  = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain   = sodium_crypto_secretbox_open($cipher, $nonce, $key);

        if ($plain === false) {
            throw new RuntimeException('Invalid share key.');
        }

        return $plain;
    }
}
