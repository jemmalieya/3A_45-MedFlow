<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260203213354 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE commande (id_commande INT AUTO_INCREMENT NOT NULL, id_user INT NOT NULL, date_creation_commande DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', statut_commande VARCHAR(150) NOT NULL, montant_total DOUBLE PRECISION NOT NULL, PRIMARY KEY(id_commande)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE commande_produit (id_ligne_commande INT AUTO_INCREMENT NOT NULL, id_commande INT NOT NULL, id_produit INT NOT NULL, quantite_commandee INT NOT NULL, INDEX IDX_DF1E9E873E314AE8 (id_commande), INDEX IDX_DF1E9E87F7384557 (id_produit), PRIMARY KEY(id_ligne_commande)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE commentaire (id INT AUTO_INCREMENT NOT NULL, id_post INT NOT NULL, contenu LONGTEXT NOT NULL, date_creation DATETIME NOT NULL, est_anonyme TINYINT(1) NOT NULL, parametres_confidentialite VARCHAR(60) NOT NULL, INDEX IDX_67F068BCD1AA708F (id_post), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE fiche_medicale (id INT AUTO_INCREMENT NOT NULL, rendez_vous_id INT DEFAULT NULL, diagnostic LONGTEXT NOT NULL, observations LONGTEXT DEFAULT NULL, resultats_examens LONGTEXT DEFAULT NULL, start_time DATETIME NOT NULL, end_time DATETIME DEFAULT NULL, duree_minutes INT DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_20D232691EF7EAA (rendez_vous_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE post (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(255) NOT NULL, contenu LONGTEXT NOT NULL, localisation VARCHAR(255) NOT NULL, img_post VARCHAR(255) NOT NULL, hashtags VARCHAR(255) DEFAULT NULL, visibilite VARCHAR(50) NOT NULL, date_creation DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', date_modification DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', est_anonyme TINYINT(1) NOT NULL, categorie VARCHAR(255) NOT NULL, humeur VARCHAR(60) DEFAULT NULL, nbr_reactions INT DEFAULT NULL, nbr_commentaires INT DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE produit (id_produit INT AUTO_INCREMENT NOT NULL, nom_produit VARCHAR(150) NOT NULL, description_produit VARCHAR(255) NOT NULL, prix_produit DOUBLE PRECISION NOT NULL, quantite_produit INT NOT NULL, image_produit VARCHAR(255) NOT NULL, categorie_produit VARCHAR(150) NOT NULL, status_produit VARCHAR(50) NOT NULL, PRIMARY KEY(id_produit)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE rendez_vous (id INT AUTO_INCREMENT NOT NULL, datetime DATETIME NOT NULL, statut VARCHAR(50) NOT NULL, mode VARCHAR(50) NOT NULL, motif VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, id_patient INT NOT NULL, id_staff INT NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE user (id INT AUTO_INCREMENT NOT NULL, cin VARCHAR(8) NOT NULL, profile_picture VARCHAR(255) DEFAULT NULL, nom VARCHAR(100) NOT NULL, prenom VARCHAR(100) NOT NULL, date_naissance DATE NOT NULL, telephone_user VARCHAR(20) NOT NULL, email_user VARCHAR(180) NOT NULL, adresse_user VARCHAR(180) DEFAULT NULL, password VARCHAR(255) NOT NULL, derniere_connexion DATETIME DEFAULT NULL, is_verified TINYINT(1) NOT NULL, statut_compte VARCHAR(30) DEFAULT NULL, UNIQUE INDEX UNIQ_8D93D64912A5F6CC (email_user), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE commande_produit ADD CONSTRAINT FK_DF1E9E873E314AE8 FOREIGN KEY (id_commande) REFERENCES commande (id_commande)');
        $this->addSql('ALTER TABLE commande_produit ADD CONSTRAINT FK_DF1E9E87F7384557 FOREIGN KEY (id_produit) REFERENCES produit (id_produit)');
        $this->addSql('ALTER TABLE commentaire ADD CONSTRAINT FK_67F068BCD1AA708F FOREIGN KEY (id_post) REFERENCES post (id)');
        $this->addSql('ALTER TABLE fiche_medicale ADD CONSTRAINT FK_20D232691EF7EAA FOREIGN KEY (rendez_vous_id) REFERENCES rendez_vous (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE commande_produit DROP FOREIGN KEY FK_DF1E9E873E314AE8');
        $this->addSql('ALTER TABLE commande_produit DROP FOREIGN KEY FK_DF1E9E87F7384557');
        $this->addSql('ALTER TABLE commentaire DROP FOREIGN KEY FK_67F068BCD1AA708F');
        $this->addSql('ALTER TABLE fiche_medicale DROP FOREIGN KEY FK_20D232691EF7EAA');
        $this->addSql('DROP TABLE commande');
        $this->addSql('DROP TABLE commande_produit');
        $this->addSql('DROP TABLE commentaire');
        $this->addSql('DROP TABLE fiche_medicale');
        $this->addSql('DROP TABLE post');
        $this->addSql('DROP TABLE produit');
        $this->addSql('DROP TABLE rendez_vous');
        $this->addSql('DROP TABLE user');
    }
}
