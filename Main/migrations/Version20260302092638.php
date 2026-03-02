<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260302092638 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fiche_medicale ADD CONSTRAINT FK_20D2326DE12AB56 FOREIGN KEY (created_by) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fiche_medicale ADD CONSTRAINT FK_20D232616FE72E1 FOREIGN KEY (updated_by) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_20D2326DE12AB56 ON fiche_medicale (created_by)');
        $this->addSql('CREATE INDEX IDX_20D232616FE72E1 ON fiche_medicale (updated_by)');
        $this->addSql('ALTER TABLE prescription ADD created_by INT NOT NULL, ADD updated_by INT DEFAULT NULL, ADD updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE prescription ADD CONSTRAINT FK_1FBFB8D9DE12AB56 FOREIGN KEY (created_by) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE prescription ADD CONSTRAINT FK_1FBFB8D916FE72E1 FOREIGN KEY (updated_by) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_1FBFB8D9DE12AB56 ON prescription (created_by)');
        $this->addSql('CREATE INDEX IDX_1FBFB8D916FE72E1 ON prescription (updated_by)');
        $this->addSql('ALTER TABLE rendez_vous ADD created_by INT NOT NULL, ADD updated_by INT DEFAULT NULL, ADD updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE datetime datetime DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE rendez_vous ADD CONSTRAINT FK_65E8AA0ADE12AB56 FOREIGN KEY (created_by) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE rendez_vous ADD CONSTRAINT FK_65E8AA0A16FE72E1 FOREIGN KEY (updated_by) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_65E8AA0ADE12AB56 ON rendez_vous (created_by)');
        $this->addSql('CREATE INDEX IDX_65E8AA0A16FE72E1 ON rendez_vous (updated_by)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fiche_medicale DROP FOREIGN KEY FK_20D2326DE12AB56');
        $this->addSql('ALTER TABLE fiche_medicale DROP FOREIGN KEY FK_20D232616FE72E1');
        $this->addSql('DROP INDEX IDX_20D2326DE12AB56 ON fiche_medicale');
        $this->addSql('DROP INDEX IDX_20D232616FE72E1 ON fiche_medicale');
        $this->addSql('ALTER TABLE prescription DROP FOREIGN KEY FK_1FBFB8D9DE12AB56');
        $this->addSql('ALTER TABLE prescription DROP FOREIGN KEY FK_1FBFB8D916FE72E1');
        $this->addSql('DROP INDEX IDX_1FBFB8D9DE12AB56 ON prescription');
        $this->addSql('DROP INDEX IDX_1FBFB8D916FE72E1 ON prescription');
        $this->addSql('ALTER TABLE prescription DROP created_by, DROP updated_by, DROP updated_at');
        $this->addSql('ALTER TABLE rendez_vous DROP FOREIGN KEY FK_65E8AA0ADE12AB56');
        $this->addSql('ALTER TABLE rendez_vous DROP FOREIGN KEY FK_65E8AA0A16FE72E1');
        $this->addSql('DROP INDEX IDX_65E8AA0ADE12AB56 ON rendez_vous');
        $this->addSql('DROP INDEX IDX_65E8AA0A16FE72E1 ON rendez_vous');
        $this->addSql('ALTER TABLE rendez_vous DROP created_by, DROP updated_by, DROP updated_at, CHANGE datetime datetime DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL');
    }
}
