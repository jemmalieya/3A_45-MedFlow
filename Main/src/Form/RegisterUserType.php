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

class RegisterUserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('cin', TextType::class, [
                'required' => true,
                'attr' => ['placeholder' => 'CIN (8 chiffres)'],
                'constraints' => [
                    new NotBlank(['message' => 'Le CIN est obligatoire.']),
                    new Regex([
                        'pattern' => '/^\d{8}$/',
                        'message' => 'Le CIN doit contenir exactement 8 chiffres.'
                    ]),
                ],
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
                
                'required' => true,
                'constraints' => [
                    new NotBlank(['message' => 'La date de naissance est obligatoire.']),
                ],
            ])

            ->add('telephoneUser', TextType::class, [
                'required' => true,
                'attr' => ['placeholder' => 'Téléphone (+216...)'],
                'constraints' => [
                    new NotBlank(['message' => 'Le téléphone est obligatoire.']),
                ],
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
                'attr' => ['placeholder' => 'Adresse (optionnel)'],
            ])

            ->add('plainPassword', PasswordType::class, [
                'required' => true,
                'mapped' => false,
                'attr' => ['placeholder' => 'Mot de passe'],
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
