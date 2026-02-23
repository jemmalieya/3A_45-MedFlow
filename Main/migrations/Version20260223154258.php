<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260223154258 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE commentaire ADD status VARCHAR(20) NOT NULL, ADD moderation_score DOUBLE PRECISION DEFAULT NULL, ADD moderation_label VARCHAR(50) DEFAULT NULL, ADD moderated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE reclamation ADD piece_jointe_resource_type VARCHAR(20) DEFAULT NULL, ADD piece_jointe_format VARCHAR(10) DEFAULT NULL, ADD piece_jointe_bytes INT DEFAULT NULL, ADD piece_jointe_original_name VARCHAR(255) DEFAULT NULL, ADD contenu_original LONGTEXT DEFAULT NULL, ADD description_original LONGTEXT DEFAULT NULL, ADD langue_originale VARCHAR(10) DEFAULT NULL, ADD contenu_francais LONGTEXT DEFAULT NULL, ADD description_francais LONGTEXT DEFAULT NULL, ADD urgence_score INT DEFAULT NULL, ADD sentiment VARCHAR(20) DEFAULT NULL, ADD translated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD analysis_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE commentaire DROP status, DROP moderation_score, DROP moderation_label, DROP moderated_at');
        $this->addSql('ALTER TABLE reclamation DROP piece_jointe_resource_type, DROP piece_jointe_format, DROP piece_jointe_bytes, DROP piece_jointe_original_name, DROP contenu_original, DROP description_original, DROP langue_originale, DROP contenu_francais, DROP description_francais, DROP urgence_score, DROP sentiment, DROP translated_at, DROP analysis_at');
    }
}
