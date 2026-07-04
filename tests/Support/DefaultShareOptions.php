<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Support;

final class DefaultShareOptions
{
    /**
     * @return array{
     *     default_expiration: string,
     *     default_max_reads: int,
     *     max_reads_options: list<int>,
     *     expiration_options: list<array{id: string, interval: string}>
     * }
     */
    public static function get(): array
    {
        return [
            'default_expiration' => '1h',
            'default_max_reads'  => 1,
            'list_page_size'     => 20,
            'max_reads_options'  => [1, 3, 10],
            'expiration_options' => [
                ['id' => '1h', 'interval' => '1 hour'],
                ['id' => '24h', 'interval' => '24 hours'],
                ['id' => '7d', 'interval' => '7 days'],
            ],
            'retention' => [
                'enabled' => true,
                'max_age' => '1 month',
            ],
        ];
    }
}
