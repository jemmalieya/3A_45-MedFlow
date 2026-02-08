<?php

namespace App\Form;

use App\Entity\FicheMedicale;
use App\Entity\RendezVous;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class FicheMedicaleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('diagnostic')
            ->add('observations')
            ->add('resultatsExamens')
            ->add('startTime')
            ->add('endTime')
            ->add('dureeMinutes')
            ->add('createdAt')
            ->add('rendezVous', EntityType::class, [
                'class' => RendezVous::class,
                'choice_label' => 'id',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => FicheMedicale::class,
        ]);
    }
}
