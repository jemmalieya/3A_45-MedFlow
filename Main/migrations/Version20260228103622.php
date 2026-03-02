<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260228103622 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user ADD last_login_ip VARCHAR(64) DEFAULT NULL, ADD last_login_country VARCHAR(2) DEFAULT NULL, ADD last_login_at DATETIME DEFAULT NULL, ADD totp_secret VARCHAR(64) DEFAULT NULL, ADD totp_enabled TINYINT(1) DEFAULT 0 NOT NULL, ADD face_login_enabled TINYINT(1) DEFAULT 0 NOT NULL, ADD face_reference_embedding JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', ADD face_enrolled_at DATETIME DEFAULT NULL, ADD face_last_verified_at DATETIME DEFAULT NULL, ADD face_failed_attempts INT DEFAULT 0 NOT NULL, ADD face_locked_until DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user DROP last_login_ip, DROP last_login_country, DROP last_login_at, DROP totp_secret, DROP totp_enabled, DROP face_login_enabled, DROP face_reference_embedding, DROP face_enrolled_at, DROP face_last_verified_at, DROP face_failed_attempts, DROP face_locked_until');
    }
}
