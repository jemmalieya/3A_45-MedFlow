<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260207140449 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE commande ADD stripe_session_id VARCHAR(255) DEFAULT NULL, ADD paid_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE commande_produit DROP FOREIGN KEY FK_DF1E9E873E314AE8');
        $this->addSql('ALTER TABLE commande_produit ADD CONSTRAINT FK_DF1E9E873E314AE8 FOREIGN KEY (id_commande) REFERENCES commande (id_commande)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE commande DROP stripe_session_id, DROP paid_at');
        $this->addSql('ALTER TABLE commande_produit DROP FOREIGN KEY FK_DF1E9E873E314AE8');
        $this->addSql('ALTER TABLE commande_produit ADD CONSTRAINT FK_DF1E9E873E314AE8 FOREIGN KEY (id_commande) REFERENCES commande (id_commande) ON DELETE CASCADE');
    }
}
