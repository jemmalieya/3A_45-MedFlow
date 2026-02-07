<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260206235249 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE commentaire DROP FOREIGN KEY FK_67F068BCD1AA708F');
        $this->addSql('DROP INDEX IDX_67F068BCD1AA708F ON commentaire');
        $this->addSql('ALTER TABLE commentaire CHANGE date_creation date_creation DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE id_post post_id INT NOT NULL');
        $this->addSql('ALTER TABLE commentaire ADD CONSTRAINT FK_67F068BC4B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_67F068BC4B89032C ON commentaire (post_id)');
        $this->addSql('ALTER TABLE post CHANGE nbr_reactions nbr_reactions INT NOT NULL, CHANGE nbr_commentaires nbr_commentaires INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE commentaire DROP FOREIGN KEY FK_67F068BC4B89032C');
        $this->addSql('DROP INDEX IDX_67F068BC4B89032C ON commentaire');
        $this->addSql('ALTER TABLE commentaire CHANGE date_creation date_creation DATETIME NOT NULL, CHANGE post_id id_post INT NOT NULL');
        $this->addSql('ALTER TABLE commentaire ADD CONSTRAINT FK_67F068BCD1AA708F FOREIGN KEY (id_post) REFERENCES post (id)');
        $this->addSql('CREATE INDEX IDX_67F068BCD1AA708F ON commentaire (id_post)');
        $this->addSql('ALTER TABLE post CHANGE nbr_reactions nbr_reactions INT DEFAULT NULL, CHANGE nbr_commentaires nbr_commentaires INT DEFAULT NULL');
    }
}
