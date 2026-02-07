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
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Email;

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
                    new Length([
                        'min' => 8,
                        'max' => 8,
                        'exactMessage' => 'Le CIN doit contenir exactement {{ limit }} chiffres.',
                    ]),
                    new Regex([
                        'pattern' => '/^\d{8}$/',
                        'message' => 'Le CIN doit contenir uniquement 8 chiffres.',
                    ]),
                ],
            ])

            ->add('nom', TextType::class, [
                'required' => true,
                'attr' => ['placeholder' => 'Nom'],
                'constraints' => [
                    new NotBlank(['message' => 'Le nom est obligatoire.']),
                    new Length(['max' => 100]),
                ],
            ])

            ->add('prenom', TextType::class, [
                'required' => true,
                'attr' => ['placeholder' => 'Prénom'],
                'constraints' => [
                    new NotBlank(['message' => 'Le prénom est obligatoire.']),
                    new Length(['max' => 100]),
                ],
            ])

            ->add('dateNaissance', DateType::class, [
                'required' => true,
                'widget' => 'single_text',
                'constraints' => [
                    new NotBlank(['message' => 'La date de naissance est obligatoire.']),
                ],
            ])

            ->add('telephoneUser', TextType::class, [
                'required' => true,
                'attr' => ['placeholder' => 'Téléphone (+216...)'],
                'constraints' => [
                    new NotBlank(['message' => 'Le téléphone est obligatoire.']),
                    new Length(['max' => 20]),
                ],
            ])

            ->add('emailUser', EmailType::class, [
                'required' => true,
                'attr' => ['placeholder' => 'Email'],
                'constraints' => [
                    new NotBlank(['message' => 'L’email est obligatoire.']),
                    new Email(['message' => 'Email invalide.']),
                ],
            ])

            ->add('adresseUser', TextType::class, [
                'required' => false,
                'attr' => ['placeholder' => 'Adresse (optionnel)'],
            ])

            ->add('plainPassword', PasswordType::class, [
                'required' => true,
                'mapped' => true,
                'attr' => ['placeholder' => 'Mot de passe'],
                'constraints' => [
                    new NotBlank(['message' => 'Le mot de passe est obligatoire.']),
                    new Length([
                        'min' => 6,
                        'minMessage' => 'Le mot de passe doit contenir au moins {{ limit }} caractères.',
                        'max' => 255
                    ]),
                ],
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
