<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250201000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create yopass_secure_shares table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE yopass_secure_shares (
            id VARCHAR(36) NOT NULL,
            creator_id INT NOT NULL,
            ciphertext LONGTEXT NOT NULL,
            expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            max_reads INT NOT NULL,
            reads_left INT NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            revoked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            payload_kind VARCHAR(16) DEFAULT \'text\' NOT NULL,
            INDEX IDX_YOPASS_CREATOR (creator_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_YOPASS_CREATOR FOREIGN KEY (creator_id) REFERENCES users (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE yopass_secure_shares');
    }
}
