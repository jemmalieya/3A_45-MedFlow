<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260210001330 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // add unique index on cin (columns already exist in entity definition)
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649ABE530DA ON `user` (cin)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        // drop unique index on cin only
        $this->addSql('DROP INDEX UNIQ_8D93D649ABE530DA ON `user`');
    }
}
