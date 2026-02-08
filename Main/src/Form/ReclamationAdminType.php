<?php

namespace App\Form;

use App\Entity\Reclamation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ReclamationAdminType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // ====== Champs remplis par USER (visibles mais non modifiables) ======
            ->add('referenceReclamation', TextType::class, [
                'label' => 'Référence',
                'disabled' => true,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('contenu', TextType::class, [
                'label' => 'Contenu',
                'disabled' => true,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'disabled' => true,
                'attr' => ['class' => 'form-control', 'rows' => 5],
            ])
            ->add('type', TextType::class, [
                'label' => 'Type',
                'disabled' => true,
                'attr' => ['class' => 'form-control'],
            ])

            // ====== Champs BACK-OFFICE (modifiables par ADMIN) ======
            ->add('statutReclamation', ChoiceType::class, [
                'label' => 'Statut',
                'choices' => [
                    'En attente' => 'EN_ATTENTE',
                    'Traitée' => 'TRAITEE',
                    'Rejetée' => 'REJETEE',
                ],
                'attr' => ['class' => 'form-control'],
            ])
            ->add('priorite', ChoiceType::class, [
                'label' => 'Priorité',
                'choices' => [
                    'Normale' => 'NORMALE',
                    'Haute' => 'HAUTE',
                    'Urgente' => 'URGENTE',
                ],
                'attr' => ['class' => 'form-control'],
            ])
            ->add('date_limite', DateTimeType::class, [
                'label' => 'Date limite',
                'required' => false,
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('date_cloture_r', DateTimeType::class, [
                'label' => 'Date de clôture',
                'required' => false,
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('pieceJointePath', TextType::class, [
                'label' => 'Pièce jointe (chemin/lien)',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])

            // ====== Champs dates système (visibles, non modifiables) ======
            ->add('date_creation_r', DateTimeType::class, [
                'label' => 'Date création',
                'disabled' => true,
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('date_modification_r', DateTimeType::class, [
                'label' => 'Dernière modification',
                'disabled' => true,
                'required' => false,
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Reclamation::class,
        ]);
    }
}
