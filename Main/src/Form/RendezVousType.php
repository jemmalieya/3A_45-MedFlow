<?php

namespace App\Form;

use App\Entity\RendezVous;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RendezVousType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $minDateTime = new \DateTime('tomorrow');
        
        $builder
            ->add('datetime', DateTimeType::class, [
                'label' => 'Date & Heure',
                'widget' => 'single_text',
                'html5' => true,
                // add a class that our template JS will target to attach Flatpickr
                'attr' => [
                    'class' => 'form-control flatpickr-input',
                    'min' => $minDateTime->format('Y-m-d\TH:i'),
                    'placeholder' => 'Sélectionner une date future',
                ],
                'required' => true,
            ])
            ->add('mode', ChoiceType::class, [
                'label' => 'Mode',
                'choices' => [
                    'Présentiel' => 'Présentiel',
                    'Distanciel' => 'Distanciel',
                ],
                'attr' => ['class' => 'form-select'],
                'required' => true,
            ])
            ->add('motif', TextareaType::class, [
                'label' => 'Motif',
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'maxlength' => '150',
                    'placeholder' => 'Décrivez le motif de votre consultation (max 150 caractères)',
                ],
                'required' => true,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RendezVous::class,
        ]);
    }
}
