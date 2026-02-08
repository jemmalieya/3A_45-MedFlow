<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260207114344 extends AbstractMigration
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
        $this->addSql('CREATE TABLE evenement (id INT AUTO_INCREMENT NOT NULL, titre_event VARCHAR(255) NOT NULL, slug_event VARCHAR(255) NOT NULL, type_event VARCHAR(255) NOT NULL, description_event VARCHAR(255) NOT NULL, objectif_event VARCHAR(255) NOT NULL, statut_event VARCHAR(255) NOT NULL, date_debut_event DATE NOT NULL, date_fin_event DATE NOT NULL, nom_lieu_event VARCHAR(255) NOT NULL, adresse_event VARCHAR(255) NOT NULL, ville_event VARCHAR(255) NOT NULL, nb_participants_max_event INT DEFAULT NULL, inscription_obligatoire_event TINYINT(1) NOT NULL, date_limite_inscription_event DATE DEFAULT NULL, email_contact_event VARCHAR(255) NOT NULL, tel_contact_event VARCHAR(30) NOT NULL, nom_organisateur_event VARCHAR(255) NOT NULL, image_couverture_event VARCHAR(255) DEFAULT NULL, visibilite_event VARCHAR(255) DEFAULT NULL, date_creation_event DATE NOT NULL, date_mise_a_jour_event DATE NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE fiche_medicale (id INT AUTO_INCREMENT NOT NULL, rendez_vous_id INT DEFAULT NULL, diagnostic LONGTEXT NOT NULL, observations LONGTEXT DEFAULT NULL, resultats_examens LONGTEXT DEFAULT NULL, start_time DATETIME NOT NULL, end_time DATETIME DEFAULT NULL, duree_minutes INT DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_20D232691EF7EAA (rendez_vous_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE post (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(255) NOT NULL, contenu LONGTEXT NOT NULL, localisation VARCHAR(255) NOT NULL, img_post VARCHAR(255) NOT NULL, hashtags VARCHAR(255) DEFAULT NULL, visibilite VARCHAR(50) NOT NULL, date_creation DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', date_modification DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', est_anonyme TINYINT(1) NOT NULL, categorie VARCHAR(255) NOT NULL, humeur VARCHAR(60) DEFAULT NULL, nbr_reactions INT DEFAULT NULL, nbr_commentaires INT DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE produit (id_produit INT AUTO_INCREMENT NOT NULL, nom_produit VARCHAR(150) NOT NULL, description_produit VARCHAR(255) NOT NULL, prix_produit DOUBLE PRECISION NOT NULL, quantite_produit INT NOT NULL, image_produit VARCHAR(255) NOT NULL, categorie_produit VARCHAR(150) NOT NULL, status_produit VARCHAR(50) NOT NULL, PRIMARY KEY(id_produit)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE reclamation (id_reclamation INT AUTO_INCREMENT NOT NULL, reference_reclamation VARCHAR(30) NOT NULL, contenu VARCHAR(150) NOT NULL, description LONGTEXT NOT NULL, type VARCHAR(50) NOT NULL, piece_jointe_path VARCHAR(255) DEFAULT NULL, statut_reclamation VARCHAR(50) NOT NULL, priorite VARCHAR(50) NOT NULL, date_limite DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', date_creation_r DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', date_modification_r DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', date_cloture_r DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id_reclamation)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE rendez_vous (id INT AUTO_INCREMENT NOT NULL, datetime DATETIME NOT NULL, statut VARCHAR(50) NOT NULL, mode VARCHAR(50) NOT NULL, motif VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, id_patient INT NOT NULL, id_staff INT NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE reponse_reclamation (id_reponse INT AUTO_INCREMENT NOT NULL, id_reclamation INT NOT NULL, message LONGTEXT NOT NULL, type_reponse VARCHAR(50) NOT NULL, date_creation_rep DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', date_modification_rep DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_C7CB5101D672A9F3 (id_reclamation), PRIMARY KEY(id_reponse)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE ressource (id INT AUTO_INCREMENT NOT NULL, evenement_id INT NOT NULL, nom_ressource VARCHAR(255) NOT NULL, categorie_ressource VARCHAR(50) NOT NULL, type_ressource VARCHAR(30) NOT NULL, chemin_fichier_ressource VARCHAR(255) DEFAULT NULL, url_externe_ressource VARCHAR(500) DEFAULT NULL, mime_type_ressource VARCHAR(100) DEFAULT NULL, taille_kb_ressource INT DEFAULT NULL, quantite_disponible_ressource INT DEFAULT NULL, unite_ressource VARCHAR(30) DEFAULT NULL, fournisseur_ressource VARCHAR(255) DEFAULT NULL, cout_estime_ressource NUMERIC(10, 3) DEFAULT NULL, est_publique_ressource TINYINT(1) NOT NULL, notes_ressource LONGTEXT DEFAULT NULL, date_creation_ressource DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', date_mise_a_jour_ressource DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_939F4544FD02F13 (evenement_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE user (id INT AUTO_INCREMENT NOT NULL, cin VARCHAR(8) NOT NULL, profile_picture VARCHAR(255) DEFAULT NULL, nom VARCHAR(100) NOT NULL, prenom VARCHAR(100) NOT NULL, date_naissance DATE NOT NULL, telephone_user VARCHAR(20) NOT NULL, email_user VARCHAR(180) NOT NULL, adresse_user VARCHAR(180) DEFAULT NULL, password VARCHAR(255) NOT NULL, derniere_connexion DATETIME DEFAULT NULL, is_verified TINYINT(1) NOT NULL, statut_compte VARCHAR(30) DEFAULT NULL, role_systeme VARCHAR(20) NOT NULL, type_staff VARCHAR(40) DEFAULT NULL, verification_token VARCHAR(255) DEFAULT NULL, token_expires_at DATETIME DEFAULT NULL, staff_request_status VARCHAR(20) DEFAULT NULL, staff_request_type VARCHAR(40) DEFAULT NULL, staff_request_message LONGTEXT DEFAULT NULL, staff_requested_at DATETIME DEFAULT NULL, staff_reviewed_at DATETIME DEFAULT NULL, staff_reviewed_by INT DEFAULT NULL, UNIQUE INDEX UNIQ_8D93D64912A5F6CC (email_user), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', available_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', delivered_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_75EA56E0FB7336F0 (queue_name), INDEX IDX_75EA56E0E3BD61CE (available_at), INDEX IDX_75EA56E016BA31DB (delivered_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE commande_produit ADD CONSTRAINT FK_DF1E9E873E314AE8 FOREIGN KEY (id_commande) REFERENCES commande (id_commande)');
        $this->addSql('ALTER TABLE commande_produit ADD CONSTRAINT FK_DF1E9E87F7384557 FOREIGN KEY (id_produit) REFERENCES produit (id_produit)');
        $this->addSql('ALTER TABLE commentaire ADD CONSTRAINT FK_67F068BCD1AA708F FOREIGN KEY (id_post) REFERENCES post (id)');
        $this->addSql('ALTER TABLE fiche_medicale ADD CONSTRAINT FK_20D232691EF7EAA FOREIGN KEY (rendez_vous_id) REFERENCES rendez_vous (id)');
        $this->addSql('ALTER TABLE reponse_reclamation ADD CONSTRAINT FK_C7CB5101D672A9F3 FOREIGN KEY (id_reclamation) REFERENCES reclamation (id_reclamation) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ressource ADD CONSTRAINT FK_939F4544FD02F13 FOREIGN KEY (evenement_id) REFERENCES evenement (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE commande_produit DROP FOREIGN KEY FK_DF1E9E873E314AE8');
        $this->addSql('ALTER TABLE commande_produit DROP FOREIGN KEY FK_DF1E9E87F7384557');
        $this->addSql('ALTER TABLE commentaire DROP FOREIGN KEY FK_67F068BCD1AA708F');
        $this->addSql('ALTER TABLE fiche_medicale DROP FOREIGN KEY FK_20D232691EF7EAA');
        $this->addSql('ALTER TABLE reponse_reclamation DROP FOREIGN KEY FK_C7CB5101D672A9F3');
        $this->addSql('ALTER TABLE ressource DROP FOREIGN KEY FK_939F4544FD02F13');
        $this->addSql('DROP TABLE commande');
        $this->addSql('DROP TABLE commande_produit');
        $this->addSql('DROP TABLE commentaire');
        $this->addSql('DROP TABLE evenement');
        $this->addSql('DROP TABLE fiche_medicale');
        $this->addSql('DROP TABLE post');
        $this->addSql('DROP TABLE produit');
        $this->addSql('DROP TABLE reclamation');
        $this->addSql('DROP TABLE rendez_vous');
        $this->addSql('DROP TABLE reponse_reclamation');
        $this->addSql('DROP TABLE ressource');
        $this->addSql('DROP TABLE user');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
