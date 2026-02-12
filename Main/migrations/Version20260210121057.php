<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260210121057 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reclamation ADD CONSTRAINT FK_CE606404A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('CREATE INDEX IDX_CE606404A76ED395 ON reclamation (user_id)');
        $this->addSql('ALTER TABLE rendez_vous ADD idPatient INT NOT NULL, ADD idStaff INT NOT NULL, DROP id_patient, DROP id_staff');
        $this->addSql('ALTER TABLE rendez_vous ADD CONSTRAINT FK_65E8AA0AA63BC19 FOREIGN KEY (idPatient) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE rendez_vous ADD CONSTRAINT FK_65E8AA0A55AAB08F FOREIGN KEY (idStaff) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_65E8AA0AA63BC19 ON rendez_vous (idPatient)');
        $this->addSql('CREATE INDEX IDX_65E8AA0A55AAB08F ON rendez_vous (idStaff)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reclamation DROP FOREIGN KEY FK_CE606404A76ED395');
        $this->addSql('DROP INDEX IDX_CE606404A76ED395 ON reclamation');
        $this->addSql('ALTER TABLE rendez_vous DROP FOREIGN KEY FK_65E8AA0AA63BC19');
        $this->addSql('ALTER TABLE rendez_vous DROP FOREIGN KEY FK_65E8AA0A55AAB08F');
        $this->addSql('DROP INDEX IDX_65E8AA0AA63BC19 ON rendez_vous');
        $this->addSql('DROP INDEX IDX_65E8AA0A55AAB08F ON rendez_vous');
        $this->addSql('ALTER TABLE rendez_vous ADD id_patient INT NOT NULL, ADD id_staff INT NOT NULL, DROP idPatient, DROP idStaff');
    }
}
