<?php

namespace App\Form;

use App\Entity\Evenement;
use App\Entity\Ressource;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;

class RessourceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
        ->add('evenement', EntityType::class, [
    'class' => Evenement::class,
    'choice_label' => 'titre_event', // ✅ affichage dans la liste
    'label' => 'Événement',
    'placeholder' => 'Choisir un événement',
  ])


  ->add('nomRessource', null, [
  'label' => 'Nom de la ressource',
  'attr' => ['placeholder' => 'Ex: Agenda PDF / Badge / Projecteur']
])

->add('categorieRessource', null, [
  'label' => 'Catégorie',
  'attr' => ['placeholder' => 'Ex: Document / Matériel / Stock']
])


            ->add('typeRessource', ChoiceType::class, [
  'label' => 'Type',
  'choices' => [
    'Fichier' => 'file',
    'Lien externe' => 'external_link',
    'Stock / Matériel' => 'stock_item',
  ],
  'attr' => ['class' => 'js-type-ressource'],
])

->add('cheminFichierRessource', null, [
  'required' => false,
  'label' => 'Chemin fichier',
  'attr' => ['class' => 'js-field-file'],
])

->add('urlExterneRessource', null, [
  'required' => false,
  'label' => 'URL externe',
  'attr' => ['class' => 'js-field-link'],
])

->add('quantiteDisponibleRessource', IntegerType::class, [
    'required' => false,
    'label' => 'Quantité disponible',
    'attr' => [
        'min' => 0,
        'placeholder' => 'Ex: 50'
    ],
])


->add('uniteRessource', null, [
  'required' => false,
  'label' => 'Unité',
  'attr' => ['class' => 'js-field-stock'],
])

->add('fournisseurRessource', null, [
  'required' => false,
  'label' => 'Fournisseur',
  'attr' => ['class' => 'js-field-stock'],
])

->add('coutEstimeRessource', null, [
  'required' => false,
  'label' => 'Coût estimé',
  'attr' => ['class' => 'js-field-stock'],
])

->add('notesRessource', TextareaType::class, [
  'required' => false,
  'label' => 'Notes',
  'attr' => ['rows' => 4],
])
;

    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Ressource::class,
        ]);
    }

    
}
