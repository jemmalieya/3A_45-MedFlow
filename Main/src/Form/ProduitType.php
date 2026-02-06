<?php

namespace App\Form;

use App\Entity\Produit;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;

class ProduitType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom_produit', TextType::class, [
                'label' => 'Nom du produit',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex: Paracétamol 500mg'
                ]
            ])
            ->add('description_produit', TextareaType::class, [
                'label' => 'Description',
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Décrivez le produit...'
                ]
            ])
            ->add('prix_produit', NumberType::class, [
                'label' => 'Prix (DT)',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => '0.00',
                    'step' => '0.01'
                ]
            ])
            ->add('quantite_produit', IntegerType::class, [
                'label' => 'Quantité en stock',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => '0',
                    'min' => 0
                ]
            ])
            ->add('categorie_produit', ChoiceType::class, [
                'label' => 'Catégorie',
                'choices' => [
                    'Sélectionner une catégorie' => '',
                    'Médicaments' => 'Médicaments',
                    'Vitamines & Compléments' => 'Vitamines & Compléments',
                    'Soins & Hygiène' => 'Soins & Hygiène',
                    'Matériel médical' => 'Matériel médical',
                    'Pansements & Bandages' => 'Pansements & Bandages',
                    'Premiers soins' => 'Premiers soins',
                    'Nutrition & Diététique' => 'Nutrition & Diététique',
                    'Bébé & Maman' => 'Bébé & Maman',
                    'Beauté & Cosmétique' => 'Beauté & Cosmétique',
                    'Accessoires' => 'Accessoires',
                ],
                'attr' => ['class' => 'form-select']
            ])
            ->add('status_produit', ChoiceType::class, [
                'label' => 'Statut',
                'choices' => [
                    'Disponible' => 'Disponible',
                    'Rupture de stock' => 'Rupture',
                    'Indisponible' => 'Indisponible',
                ],
                'attr' => ['class' => 'form-select d-none'], // ✅ Ajout de d-none
                'label_attr' => ['class' => 'd-none'] // ✅ Cache aussi le label
            ])
            ->add('image_produit', UrlType::class, [
                'label' => 'URL de l\'image',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'https://exemple.com/image.jpg'
                ]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Produit::class,
        ]);
    }
}