<?php

namespace App\Form;

use App\Entity\Evenement;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EvenementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titre_event', TextType::class, [
                'label' => 'Titre',
                'attr' => ['placeholder' => 'Ex: Journée de don du sang']
            ])
            ->add('slug_event', TextType::class, [
                'label' => 'Slug (url-friendly)',
                'attr' => ['placeholder' => 'ex: journee-don-du-sang']
            ])
            ->add('type_event', ChoiceType::class, [
                'label' => 'Type',
                'choices' => [
                    'Campagne' => 'Campagne',
                    'Conférence' => 'Conférence',
                    'Atelier' => 'Atelier',
                    'Caritatif' => 'Caritatif',
                    'Autre' => 'Autre',
                ],
                'placeholder' => 'Choisir un type'
            ])
            ->add('description_event', TextareaType::class, [
                'label' => 'Description',
                'attr' => ['rows' => 4, 'placeholder' => 'Décrivez l’événement...']
            ])
            ->add('objectif_event', TextareaType::class, [
                'label' => 'Objectif',
                'attr' => ['rows' => 3, 'placeholder' => 'Objectif principal...']
            ])
            ->add('statut_event', ChoiceType::class, [
                'label' => 'Statut',
                'choices' => [
                    'Brouillon' => 'Brouillon',
                    'Publié' => 'Publié',
                    'Annulé' => 'Annulé',
                ],
                'placeholder' => 'Choisir un statut'
            ])
            ->add('date_debut_event', DateType::class, [
                'label' => 'Date début',
                'widget' => 'single_text'
            ])
            ->add('date_fin_event', DateType::class, [
                'label' => 'Date fin',
                'widget' => 'single_text'
            ])
            ->add('nom_lieu_event', TextType::class, [
                'label' => 'Lieu (nom)',
                'attr' => ['placeholder' => 'Ex: Hôpital Charles Nicolle']
            ])
            ->add('adresse_event', TextType::class, [
                'label' => 'Adresse',
                'attr' => ['placeholder' => 'Rue ...']
            ])
            ->add('ville_event', TextType::class, [
                'label' => 'Ville',
                'attr' => ['placeholder' => 'Tunis']
            ])
            ->add('nb_participants_max_event', IntegerType::class, [
                'label' => 'Max participants',
                'required' => false,
                'attr' => ['min' => 1, 'placeholder' => 'Ex: 200']
            ])
            ->add('inscription_obligatoire_event', CheckboxType::class, [
                'label' => 'Inscription obligatoire ?',
                'required' => false
            ])
            ->add('date_limite_inscription_event', DateType::class, [
                'label' => 'Date limite inscription',
                'required' => false,
                'widget' => 'single_text'
            ])
            ->add('email_contact_event', EmailType::class, [
                'label' => 'Email contact',
                'attr' => ['placeholder' => 'contact@medflow.tn']
            ])
            ->add('tel_contact_event', TelType::class, [
                'label' => 'Téléphone contact',
                'attr' => ['placeholder' => '+216 ...']
            ])
            ->add('nom_organisateur_event', TextType::class, [
                'label' => 'Organisateur',
                'attr' => ['placeholder' => 'Nom / Service']
            ])
            ->add('image_couverture_event', UrlType::class, [
                'label' => 'Image (URL)',
                'required' => false,
                'attr' => ['placeholder' => 'https://.../image.jpg']
            ])
            ->add('visibilite_event', ChoiceType::class, [
                'label' => 'Visibilité',
                'required' => false,
                'choices' => [
                    'Public' => 'Public',
                    'Privé' => 'Prive',
                ],
                'placeholder' => 'Choisir...'
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Evenement::class,
        ]);
    }
}
