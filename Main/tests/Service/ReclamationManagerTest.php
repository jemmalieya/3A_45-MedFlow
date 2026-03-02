<?php

namespace App\Tests\Service;

use App\Entity\Reclamation;
use App\Entity\User;
use App\Service\ReclamationManager;
use PHPUnit\Framework\TestCase;

class ReclamationManagerTest extends TestCase
{
    private function createValidReclamation(): Reclamation
    {
        $reclamation = new Reclamation();

        $reclamation->setContenu('Produit défectueux');
        $reclamation->setDescription('Le produit reçu est cassé et inutilisable.');
        $reclamation->setType('Produit');

        $reclamation->setDateCreationR(new \DateTimeImmutable());
        $reclamation->setDateLimite(
            (new \DateTimeImmutable())->modify('+2 days')
        );

        // User obligatoire (nullable:false)
        $user = new User();
        $reclamation->setUser($user);

        return $reclamation;
    }

    public function testValidReclamation()
    {
        $manager = new ReclamationManager();
        $reclamation = $this->createValidReclamation();

        $this->assertTrue($manager->validate($reclamation));
    }

    public function testDateLimiteBeforeDateCreation()
    {
        $this->expectException(\InvalidArgumentException::class);

        $manager = new ReclamationManager();
        $reclamation = $this->createValidReclamation();

        $reclamation->setDateLimite(
            (new \DateTimeImmutable())->modify('-1 day')
        );

        $manager->validate($reclamation);
    }

    public function testReclamationWithoutContenu()
    {
        $this->expectException(\InvalidArgumentException::class);

        $manager = new ReclamationManager();
        $reclamation = $this->createValidReclamation();

        $reclamation->setContenu('');

        $manager->validate($reclamation);
    }

    public function testReclamationWithoutDescription()
    {
        $this->expectException(\InvalidArgumentException::class);

        $manager = new ReclamationManager();
        $reclamation = $this->createValidReclamation();

        $reclamation->setDescription('');

        $manager->validate($reclamation);
    }

    public function testReclamationWithoutType()
    {
        $this->expectException(\InvalidArgumentException::class);

        $manager = new ReclamationManager();
        $reclamation = $this->createValidReclamation();

        $reclamation->setType('');

        $manager->validate($reclamation);
    }
}