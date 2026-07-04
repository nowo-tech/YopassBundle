<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250703000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create yopass_share_access_logs table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE yopass_share_access_logs (
            id VARCHAR(36) NOT NULL,
            share_id VARCHAR(36) NOT NULL,
            accessed_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            read_number INT NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(512) DEFAULT NULL,
            INDEX IDX_YOPASS_ACCESS_LOG_SHARE (share_id),
            INDEX IDX_YOPASS_ACCESS_LOG_ACCESSED (accessed_at),
            PRIMARY KEY(id),
            CONSTRAINT FK_YOPASS_ACCESS_LOG_SHARE FOREIGN KEY (share_id) REFERENCES yopass_secure_shares (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE yopass_share_access_logs');
    }
}
