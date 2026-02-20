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
use Symfony\Component\Form\Extension\Core\Type\FileType;

use Symfony\Component\Validator\Constraints\File;

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
                'placeholder' => 'Sélectionner une catégorie',
                'choices' => [
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

            // ✅ Status en radios
            ->add('status_produit', ChoiceType::class, [
                'label' => false,
                'choices' => [
                    'Disponible' => 'Disponible',
                    'Rupture' => 'Rupture',
                    'Indisponible' => 'Indisponible',
                ],
                'expanded' => true,
                'multiple' => false,
                'data' => $options['data']->getStatusProduit() ?? 'Disponible',
                'attr' => ['class' => 'status-radio-group'],
            ])

            // ✅ Upload image (non mappé)
            ->add('imageFile', FileType::class, [
                'label' => 'Image du produit',
                'mapped' => false,
                'required' => ($options['mode'] === 'create'),
                'attr' => ['class' => 'form-control'],
                'constraints' => [
                    new File([
                        'maxSize' => '3M',
                        'mimeTypes' => ['image/jpeg', 'image/png', 'image/webp'],
                        'mimeTypesMessage' => 'Formats acceptés : JPG, PNG, WEBP',
                    ])
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Produit::class,
            'mode' => 'create', // ✅ create/edit
        ]);
    }
}