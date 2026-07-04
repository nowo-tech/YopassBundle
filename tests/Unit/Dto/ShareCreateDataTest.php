<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Dto;

use DateTimeImmutable;
use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Tests\Support\DefaultShareOptions;
use PHPUnit\Framework\TestCase;

final class ShareCreateDataTest extends TestCase
{
    /**
     * @return list<array{id: string, interval: string}>
     */
    private function expirationOptions(): array
    {
        return DefaultShareOptions::get()['expiration_options'];
    }

    /**
     * @dataProvider expiresInProvider
     */
    public function testResolveExpiresAt(string $expiresIn, string $modifier): void
    {
        $data            = new ShareCreateData();
        $data->expiresIn = $expiresIn;

        $before = new DateTimeImmutable($modifier);
        $after  = $data->resolveExpiresAt($this->expirationOptions());

        self::assertGreaterThan($before->getTimestamp() - 5, $after->getTimestamp());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function expiresInProvider(): iterable
    {
        yield '1 hour' => ['1h', '+1 hour'];
        yield '24 hours' => ['24h', '+24 hours'];
        yield '7 days' => ['7d', '+7 days'];
        yield 'default fallback' => ['invalid', '+1 hour'];
    }

    public function testResolveExpiresAtUsesFirstOptionWhenUnknown(): void
    {
        $data            = new ShareCreateData();
        $data->expiresIn = 'unknown';

        $after = $data->resolveExpiresAt([
            ['id' => '48h', 'interval' => '48 hours'],
            ['id' => '30d', 'interval' => '30 days'],
        ]);

        $before = new DateTimeImmutable('+48 hours');
        self::assertGreaterThan($before->getTimestamp() - 5, $after->getTimestamp());
    }
}
