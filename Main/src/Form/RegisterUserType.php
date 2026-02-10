<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Date;
use Symfony\Component\Validator\Constraints as Assert;




class RegisterUserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
          ->add('cin', TextType::class, [
        'constraints' => [
            new NotBlank(['message' => 'Le CIN est obligatoire.']),
           /* new Regex([
                'pattern' => '/^\d{8}$/',
                'message' => 'Le CIN doit contenir exactement 8 chiffres.'
            ])*/
        ]
    ])

            ->add('nom', TextType::class, [
                'required' => true,
                'attr' => ['placeholder' => 'Nom'],
                'constraints' => [
                    new NotBlank(['message' => 'Le nom est obligatoire.']),
                ],
            ])

            ->add('prenom', TextType::class, [
                'required' => true,
                'attr' => ['placeholder' => 'Prénom'],
                'constraints' => [
                  new NotBlank(['message' => 'Le prénom est obligatoire.']),
                ],
            ])

            ->add('dateNaissance', DateType::class, [
            'widget' => 'single_text',
            /*'constraints' => [
                new NotBlank(['message' => 'La date de naissance est obligatoire.']),
            ],*/
            ])

             ->add('telephoneUser', TextType::class, [
        'constraints' => [
            new NotBlank(['message' => 'Le téléphone est obligatoire.']),
           /* new Regex([
                'pattern' => "/^\+?\d{8,15}$/",
                'message' => 'Téléphone invalide (ex: 54430709 ou +21654430709).'
            ])*/
        ]
    ])

            ->add('emailUser', EmailType::class, [
                'required' => true,
                'attr' => ['placeholder' => 'Email'],
                'constraints' => [
                    new NotBlank(['message' => 'L’email est obligatoire.']),
                ],
            ])

            ->add('adresseUser', TextType::class, [
            'required' => false,
            'constraints' => [
                new Assert\Length(['max' => 180]),
            ],
            ])

            ->add('plainPassword', PasswordType::class, [
                'required' => true,
                'mapped' => false,
                'attr' => ['placeholder' => 'Mot de passe'],
                'constraints' => [
                    new NotBlank(['message' => 'Le mot de passe est obligatoire.']),
                    new Assert\Length(['min' => 8, 'minMessage' => 'Le mot de passe doit contenir au moins {{ limit }} caractères.']),
                    new Regex([
                        'pattern' => '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&]).{8,}$/',
                        'message' => 'Le mot de passe doit contenir: lettres (minuscules + majuscules), chiffres et caractères spéciaux (@$!%*?&).'
                    ])
                ]
            ])

            ->add('profilePictureFile', FileType::class, [
                'required' => false,
                'mapped' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}