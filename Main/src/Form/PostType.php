<?php

namespace App\Form;

use App\Entity\Post;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotNull;

class PostType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titre', TextType::class, [
                'label' => 'Titre',
            ])
            ->add('contenu', TextareaType::class, [
                'label' => 'Contenu',
                'attr' => ['rows' => 6],
            ])
        ->add('categorie', ChoiceType::class, [
    'label' => 'Catégorie',
    'choices' => [
        'Actualité' => 'Actualité',
        'Service' => 'Service',
        'Rendez-vous' => 'Rendez-vous',
        'Laboratoire' => 'Laboratoire',
        'Santé' => 'Santé',
        'Conseils' => 'Conseils',
        'Urgence' => 'Urgence',
        'Business' => 'Business',
    ],
    'placeholder' => 'Choisir une catégorie',
    'attr' => [
        'class' => 'form-select'
    ],
])

            ->add('hashtags', TextType::class, [
                'label' => 'Hashtags',
                'required' => false,
                'help' => 'Ex: #news #health #rdv',
            ])
            ->add('localisation', TextType::class, [
                'label' => 'Localisation',
            ])
            ->add('img_post', FileType::class, [
    'label' => 'Image du post',
    'mapped' => false, // ❗ important
    'required' => false,
    'constraints' => [
        new File([
            'maxSize' => '5M',
            'mimeTypes' => [
                'image/jpeg',
                'image/png',
                'image/webp',
            ],
            'mimeTypesMessage' => 'Veuillez choisir une image valide (JPG, PNG, WEBP)',
        ])
    ],
])
            ->add('visibilite', ChoiceType::class, [
                'label' => 'Visibilité',
                'choices' => [
                    'Public' => 'PUBLIC',
                    'Privé' => 'PRIVE',
                    'Amis' => 'AMIS',
                ],
            ])
            ->add('humeur', ChoiceType::class, [
    'label' => 'Humeur',
    'required' => false,
    'choices' => [
        'Heureux ' => 'Heureux',
        'Motivé ' => 'Motivé',
        'Satisfait ' => 'Satisfait',
        'Confiant ' => 'Confiant',
        'Stressé ' => 'Stressé',
        'Inquiet ' => 'Inquiet',
    ],
    'placeholder' => 'Sélectionner une humeur',
    'attr' => [
        'class' => 'form-select'
    ],
])

            ->add('est_anonyme', CheckboxType::class, [
                'label' => 'Publier en anonyme',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Post::class,
        ]);
    }
}
