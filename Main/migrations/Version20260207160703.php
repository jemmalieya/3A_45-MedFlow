<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260207160703 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE commande_produit ADD CONSTRAINT FK_DF1E9E873E314AE8 FOREIGN KEY (id_commande) REFERENCES commande (id_commande)');
        $this->addSql('ALTER TABLE commande_produit ADD CONSTRAINT FK_DF1E9E87F7384557 FOREIGN KEY (id_produit) REFERENCES produit (id_produit)');
        $this->addSql('ALTER TABLE prescription ADD fiche_medicale_id INT NOT NULL');
        $this->addSql('ALTER TABLE prescription ADD CONSTRAINT FK_1FBFB8D99A99F4BC FOREIGN KEY (fiche_medicale_id) REFERENCES fiche_medicale (id)');
        $this->addSql('CREATE INDEX IDX_1FBFB8D99A99F4BC ON prescription (fiche_medicale_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE commande_produit DROP FOREIGN KEY FK_DF1E9E873E314AE8');
        $this->addSql('ALTER TABLE commande_produit DROP FOREIGN KEY FK_DF1E9E87F7384557');
        $this->addSql('ALTER TABLE prescription DROP FOREIGN KEY FK_1FBFB8D99A99F4BC');
        $this->addSql('DROP INDEX IDX_1FBFB8D99A99F4BC ON prescription');
        $this->addSql('ALTER TABLE prescription DROP fiche_medicale_id');
    }
}
