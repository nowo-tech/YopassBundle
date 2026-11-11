<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration\DddHost\Domain;

/**
 * DDD host aggregate: plain PHP, mapped by XML only (`config/doctrine/Ledger.orm.xml`). Used to hold pending changes
 * in the application's unit of work while the bundle writes shares.
 */
class Ledger
{
    public function __construct(
        private readonly string $id,
        private string $title,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function rename(string $title): void
    {
        $this->title = $title;
    }

    public function title(): string
    {
        return $this->title;
    }
}
