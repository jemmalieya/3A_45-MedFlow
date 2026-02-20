<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260220142032 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reaction DROP FOREIGN KEY FK_A4D707F74B89032C');
        $this->addSql('ALTER TABLE reaction DROP FOREIGN KEY FK_A4D707F7A76ED395');
        $this->addSql('DROP TABLE reaction');
        $this->addSql('ALTER TABLE commande DROP FOREIGN KEY FK_6EEAA67D6B3CA4B');
        $this->addSql('DROP INDEX IDX_6EEAA67D6B3CA4B ON commande');
        $this->addSql('ALTER TABLE commentaire DROP FOREIGN KEY FK_67F068BCA76ED395');
        $this->addSql('DROP INDEX IDX_67F068BCA76ED395 ON commentaire');
        $this->addSql('ALTER TABLE commentaire DROP user_id');
        $this->addSql('ALTER TABLE evenement DROP demandes_json');
        $this->addSql('ALTER TABLE post DROP FOREIGN KEY FK_5A8A6C8DA76ED395');
        $this->addSql('DROP INDEX IDX_5A8A6C8DA76ED395 ON post');
        $this->addSql('ALTER TABLE post DROP user_id, DROP moderation_status, DROP moderation_message, DROP moderation_seen, DROP is_approved');
        $this->addSql('ALTER TABLE produit CHANGE image_produit image_produit VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE reclamation DROP FOREIGN KEY FK_CE606404A76ED395');
        $this->addSql('DROP INDEX IDX_CE606404A76ED395 ON reclamation');
        $this->addSql('ALTER TABLE reclamation DROP user_id, DROP notification_envoyee');
        $this->addSql('ALTER TABLE rendez_vous DROP FOREIGN KEY FK_65E8AA0A55AAB08F');
        $this->addSql('ALTER TABLE rendez_vous DROP FOREIGN KEY FK_65E8AA0AA63BC19');
        $this->addSql('DROP INDEX IDX_65E8AA0AA63BC19 ON rendez_vous');
        $this->addSql('DROP INDEX IDX_65E8AA0A55AAB08F ON rendez_vous');
        $this->addSql('ALTER TABLE rendez_vous ADD id_patient INT NOT NULL, ADD id_staff INT NOT NULL, DROP idPatient, DROP idStaff, CHANGE datetime datetime DATETIME NOT NULL');
        $this->addSql('ALTER TABLE reponse_reclamation DROP is_read');
        $this->addSql('DROP INDEX UNIQ_8D93D649ABE530DA ON user');
        $this->addSql('ALTER TABLE user DROP google_id, DROP role_systeme, DROP type_staff, DROP verification_token, DROP token_expires_at, DROP reset_token, DROP reset_token_expires_at, DROP staff_request_status, DROP staff_request_type, DROP staff_request_message, DROP staff_requested_at, DROP staff_reviewed_at, DROP staff_reviewed_by, DROP ban_reason, DROP banned_at, DROP staff_request_proof_path, DROP staff_documents, DROP staff_request_reason');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE reaction (id INT AUTO_INCREMENT NOT NULL, post_id INT NOT NULL, user_id INT NOT NULL, type VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_A4D707F74B89032C (post_id), INDEX IDX_A4D707F7A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE reaction ADD CONSTRAINT FK_A4D707F74B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reaction ADD CONSTRAINT FK_A4D707F7A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commande ADD CONSTRAINT FK_6EEAA67D6B3CA4B FOREIGN KEY (id_user) REFERENCES user (id)');
        $this->addSql('CREATE INDEX IDX_6EEAA67D6B3CA4B ON commande (id_user)');
        $this->addSql('ALTER TABLE commentaire ADD user_id INT NOT NULL');
        $this->addSql('ALTER TABLE commentaire ADD CONSTRAINT FK_67F068BCA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('CREATE INDEX IDX_67F068BCA76ED395 ON commentaire (user_id)');
        $this->addSql('ALTER TABLE evenement ADD demandes_json LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE post ADD user_id INT DEFAULT NULL, ADD moderation_status VARCHAR(12) DEFAULT \'PENDING\' NOT NULL, ADD moderation_message VARCHAR(255) DEFAULT NULL, ADD moderation_seen TINYINT(1) DEFAULT 0 NOT NULL, ADD is_approved TINYINT(1) NOT NULL');
        $this->addSql('ALTER TABLE post ADD CONSTRAINT FK_5A8A6C8DA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_5A8A6C8DA76ED395 ON post (user_id)');
        $this->addSql('ALTER TABLE produit CHANGE image_produit image_produit VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE reclamation ADD user_id INT NOT NULL, ADD notification_envoyee TINYINT(1) NOT NULL');
        $this->addSql('ALTER TABLE reclamation ADD CONSTRAINT FK_CE606404A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('CREATE INDEX IDX_CE606404A76ED395 ON reclamation (user_id)');
        $this->addSql('ALTER TABLE rendez_vous ADD idPatient INT NOT NULL, ADD idStaff INT NOT NULL, DROP id_patient, DROP id_staff, CHANGE datetime datetime DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE rendez_vous ADD CONSTRAINT FK_65E8AA0A55AAB08F FOREIGN KEY (idStaff) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE rendez_vous ADD CONSTRAINT FK_65E8AA0AA63BC19 FOREIGN KEY (idPatient) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_65E8AA0AA63BC19 ON rendez_vous (idPatient)');
        $this->addSql('CREATE INDEX IDX_65E8AA0A55AAB08F ON rendez_vous (idStaff)');
        $this->addSql('ALTER TABLE reponse_reclamation ADD is_read TINYINT(1) NOT NULL');
        $this->addSql('ALTER TABLE user ADD google_id VARCHAR(255) DEFAULT NULL, ADD role_systeme VARCHAR(20) NOT NULL, ADD type_staff VARCHAR(40) DEFAULT NULL, ADD verification_token VARCHAR(255) DEFAULT NULL, ADD token_expires_at DATETIME DEFAULT NULL, ADD reset_token VARCHAR(255) DEFAULT NULL, ADD reset_token_expires_at DATETIME DEFAULT NULL, ADD staff_request_status VARCHAR(20) DEFAULT NULL, ADD staff_request_type VARCHAR(40) DEFAULT NULL, ADD staff_request_message LONGTEXT DEFAULT NULL, ADD staff_requested_at DATETIME DEFAULT NULL, ADD staff_reviewed_at DATETIME DEFAULT NULL, ADD staff_reviewed_by INT DEFAULT NULL, ADD ban_reason LONGTEXT DEFAULT NULL, ADD banned_at DATETIME DEFAULT NULL, ADD staff_request_proof_path VARCHAR(255) DEFAULT NULL, ADD staff_documents JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', ADD staff_request_reason LONGTEXT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649ABE530DA ON user (cin)');
    }
}
