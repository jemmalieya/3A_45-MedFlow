<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260207114615 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user ADD role_systeme VARCHAR(20) NOT NULL, ADD type_staff VARCHAR(40) DEFAULT NULL, ADD verification_token VARCHAR(255) DEFAULT NULL, ADD token_expires_at DATETIME DEFAULT NULL, ADD staff_request_status VARCHAR(20) DEFAULT NULL, ADD staff_request_type VARCHAR(40) DEFAULT NULL, ADD staff_request_message LONGTEXT DEFAULT NULL, ADD staff_requested_at DATETIME DEFAULT NULL, ADD staff_reviewed_at DATETIME DEFAULT NULL, ADD staff_reviewed_by INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user DROP role_systeme, DROP type_staff, DROP verification_token, DROP token_expires_at, DROP staff_request_status, DROP staff_request_type, DROP staff_request_message, DROP staff_requested_at, DROP staff_reviewed_at, DROP staff_reviewed_by');
    }
}
