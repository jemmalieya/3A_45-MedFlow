<?php

namespace App\Tests\Service;

use App\Entity\RendezVous;
use App\Service\RendezVousManager;
use PHPUnit\Framework\TestCase;

class RendezVousManagerTest extends TestCase
{
    public function testValidRendezVous(): void
    {
        $rdv = new RendezVous();
        $rdv->setMotif('Consultation générale pour contrôle annuel');
        $rdv->setMode('Présentiel');
        $rdv->setDatetime((new \DateTime('now'))->modify('+2 days'));

        $manager = new RendezVousManager();
        $this->assertTrue($manager->validate($rdv));
    }

    public function testRendezVousWithoutMotif(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $rdv = new RendezVous();
        $rdv->setMode('En ligne');
        $rdv->setDatetime((new \DateTime('now'))->modify('+1 day'));

        $manager = new RendezVousManager();
        $manager->validate($rdv);
    }

    public function testRendezVousMotifTooShort(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $rdv = new RendezVous();
        $rdv->setMotif('abc'); // < 5
        $rdv->setMode('Présentiel');
        $rdv->setDatetime((new \DateTime('now'))->modify('+1 day'));

        $manager = new RendezVousManager();
        $manager->validate($rdv);
    }

    public function testRendezVousWithoutDatetime(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $rdv = new RendezVous();
        $rdv->setMotif('Consultation de suivi après traitement');
        $rdv->setMode('Présentiel');

        $manager = new RendezVousManager();
        $manager->validate($rdv);
    }

    public function testRendezVousDatetimeInPast(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $rdv = new RendezVous();
        $rdv->setMotif('Consultation de suivi après traitement');
        $rdv->setMode('En ligne');
        $rdv->setDatetime((new \DateTime('now'))->modify('-2 hours')); // passé

        $manager = new RendezVousManager();
        $manager->validate($rdv);
    }

    public function testRendezVousWithoutMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $rdv = new RendezVous();
        $rdv->setMotif('Consultation générale pour contrôle annuel');
        $rdv->setDatetime((new \DateTime('now'))->modify('+1 day'));

        $manager = new RendezVousManager();
        $manager->validate($rdv);
    }

    public function testRendezVousInvalidUrgencyLevel(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $rdv = new RendezVous();
        $rdv->setMotif('Consultation générale pour contrôle annuel');
        $rdv->setMode('Présentiel');
        $rdv->setDatetime((new \DateTime('now'))->modify('+1 day'));
        $rdv->setUrgencyLevel('urgent'); // pas dans low|medium|high

        $manager = new RendezVousManager();
        $manager->validate($rdv);
    }
}