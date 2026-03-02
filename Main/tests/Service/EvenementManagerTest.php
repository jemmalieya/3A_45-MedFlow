<?php

namespace App\Tests\Service;

use App\Entity\Evenement;
use App\Service\EvenementManager;
use PHPUnit\Framework\TestCase;

class EvenementManagerTest extends TestCase
{
    private function makeValidEvenement(): Evenement
    {
        $e = new Evenement();

        $e->setTitreEvent('Journée de don du sang');
        $e->setSlugEvent('journee-don-du-sang');
        $e->setTypeEvent('Campagne');
        $e->setDescriptionEvent(str_repeat('a', 30));
        $e->setObjectifEvent(str_repeat('b', 20));
        $e->setStatutEvent('Publié');

        $e->setDateDebutEvent(new \DateTime('+2 days'));
        $e->setDateFinEvent(new \DateTime('+3 days'));

        $e->setNomLieuEvent('Hopital');
        $e->setAdresseEvent('Rue X');
        $e->setVilleEvent('Tunis');

        $e->setNbParticipantsMaxEvent(10);

        $e->setInscriptionObligatoireEvent(false);
        $e->setDateLimiteInscriptionEvent(null);

        $e->setEmailContactEvent('contact@medflow.tn');
        $e->setTelContactEvent('+21612345678');
        $e->setNomOrganisateurEvent('MedFlow');

        $e->setDateCreationEvent(new \DateTime('-1 day'));
        $e->setDateMiseAJourEvent(new \DateTime());

        return $e;
    }

    public function testValidEvenement(): void
    {
        $e = $this->makeValidEvenement();
        $manager = new EvenementManager();

        $this->assertTrue($manager->validate($e));
    }

    public function testEvenementWithoutTitre(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $e = $this->makeValidEvenement();
        $e->setTitreEvent('');

        $manager = new EvenementManager();
        $manager->validate($e);
    }

    public function testEvenementWithDateFinBeforeDateDebut(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $e = $this->makeValidEvenement();
        $e->setDateDebutEvent(new \DateTime('+5 days'));
        $e->setDateFinEvent(new \DateTime('+2 days'));

        $manager = new EvenementManager();
        $manager->validate($e);
    }

    public function testInscriptionObligatoireWithoutDateLimite(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $e = $this->makeValidEvenement();
        $e->setInscriptionObligatoireEvent(true);
        $e->setDateLimiteInscriptionEvent(null);

        $manager = new EvenementManager();
        $manager->validate($e);
    }

    // ============================
    // BONUS (TES RÈGLES MÉTIER ENTITÉ)
    // ============================

    public function testAddDemandeThrowsWhenEmailMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $e = $this->makeValidEvenement();
        $e->addDemande([
            'nom' => 'Ali',
            'email' => '',
            'tel' => '+21611111111',
            'message' => 'Je veux participer'
        ]);
    }

    public function testAddDemandeThrowsWhenDuplicateEmail(): void
    {
        $this->expectException(\RuntimeException::class);

        $e = $this->makeValidEvenement();

        $e->addDemande([
            'nom' => 'Ali',
            'email' => 'ali@mail.com',
            'tel' => '+21611111111',
            'message' => 'Première demande'
        ]);

        // duplicate
        $e->addDemande([
            'nom' => 'Ali 2',
            'email' => 'ali@mail.com',
            'tel' => '+21622222222',
            'message' => 'Deuxième demande'
        ]);
    }
}