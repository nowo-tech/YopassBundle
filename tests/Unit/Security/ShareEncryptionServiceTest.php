<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Security;

use Nowo\YopassBundle\Security\ShareEncryptionService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use const JSON_THROW_ON_ERROR;
use const SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING;
use const SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

final class ShareEncryptionServiceTest extends TestCase
{
    private ShareEncryptionService $service;

    protected function setUp(): void
    {
        $this->service = new ShareEncryptionService();
    }

    public function testEncryptAndDecryptRoundTrip(): void
    {
        $payload   = json_encode(['kind' => 'text', 'text' => 'super-secret-password-123!'], JSON_THROW_ON_ERROR);
        $encrypted = $this->service->encryptPayload($payload);

        self::assertStringContainsString('"mode":"key"', $encrypted['ciphertext']);
        self::assertNotEmpty($encrypted['key']);

        $decrypted = $this->service->decryptEnvelope($encrypted['ciphertext'], $encrypted['key']);

        self::assertSame($payload, $decrypted);
    }

    public function testDecryptWithWrongKeyFails(): void
    {
        $encrypted = $this->service->encryptPayload('{"kind":"text","text":"secret"}');
        $wrongKey  = sodium_bin2base64(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

        $this->expectException(RuntimeException::class);

        $this->service->decryptEnvelope($encrypted['ciphertext'], $wrongKey);
    }
}
