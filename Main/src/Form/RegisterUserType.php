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
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Date;
use Symfony\Component\Validator\Constraints as Assert;




class RegisterUserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $readonlyLookup = array_fill_keys($options['readonly_fields'] ?? [], true);
        $isGoogleSignup = (bool) ($options['google_signup'] ?? false);

        $builder
          ->add('cin', TextType::class, [
                'required' => false,
        'constraints' => [
            new NotBlank(['message' => 'Le CIN est obligatoire.']),
           /* new Regex([
                'pattern' => '/^\d{8}$/',
                'message' => 'Le CIN doit contenir exactement 8 chiffres.'
            ])*/
        ]
    ])

            ->add('nom', TextType::class, [
                'required' => false,
                'attr' => array_merge(
                    ['placeholder' => 'Nom'],
                    isset($readonlyLookup['nom']) ? ['readonly' => true, 'autocomplete' => 'off'] : []
                ),
                'constraints' => [
                    new NotBlank(['message' => 'Le nom est obligatoire.']),
                ],
            ])

            ->add('prenom', TextType::class, [
                'required' => false,
                'attr' => array_merge(
                    ['placeholder' => 'Prénom'],
                    isset($readonlyLookup['prenom']) ? ['readonly' => true, 'autocomplete' => 'off'] : []
                ),
                'constraints' => [
                  new NotBlank(['message' => 'Le prénom est obligatoire.']),
                ],
            ])

            ->add('dateNaissance', DateType::class, [
            'widget' => 'single_text',
            'required' => false,
            'attr' => array_merge(
                [],
                isset($readonlyLookup['dateNaissance']) ? ['readonly' => true, 'autocomplete' => 'off'] : []
            ),
            /*'constraints' => [
                new NotBlank(['message' => 'La date de naissance est obligatoire.']),
            ],*/
            ])

             ->add('telephoneUser', TextType::class, [
           'required' => false,
        'constraints' => [
            new NotBlank(['message' => 'Le téléphone est obligatoire.']),
           /* new Regex([
                'pattern' => "/^\+?\d{8,15}$/",
                'message' => 'Téléphone invalide (ex: 54430709 ou +21654430709).'
            ])*/
        ],
        'attr' => array_merge(
            [],
            isset($readonlyLookup['telephoneUser']) ? ['readonly' => true, 'autocomplete' => 'off'] : []
        ),
    ])

            ->add('emailUser', EmailType::class, [
                'required' => false,
                'attr' => array_merge(
                    ['placeholder' => 'Email'],
                    isset($readonlyLookup['emailUser']) ? ['readonly' => true, 'autocomplete' => 'off'] : []
                ),
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

            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'required' => false,
                'mapped' => false,
                'invalid_message' => 'Les mots de passe ne correspondent pas.',
                'first_options' => [
                    'label' => 'Mot de passe',
                    'attr' => ['placeholder' => 'Mot de passe'],
                ],
                'second_options' => [
                    'label' => 'Confirmer le mot de passe',
                    'attr' => ['placeholder' => 'Confirmer le mot de passe'],
                ],
                'constraints' => $isGoogleSignup
                    ? []
                    : [
                        new NotBlank(['message' => 'Le mot de passe est obligatoire.']),
                        new Assert\Length(['min' => 8, 'minMessage' => 'Le mot de passe doit contenir au moins {{ limit }} caractères.']),
                        new Regex([
                            'pattern' => '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&]).{8,}$/',
                            'message' => 'Le mot de passe doit contenir: lettres (minuscules + majuscules), chiffres et caractères spéciaux (@$!%*?&).'
                        ]),
                    ],
            ])

            ->add('acceptTerms', CheckboxType::class, [
                'mapped' => false,
                'required' => false,
                'label' => false,
                'constraints' => [
                    new Assert\IsTrue([
                        'message' => 'Vous devez accepter les Conditions d’utilisation pour continuer.',
                    ]),
                ],
            ])

            ->add('profilePictureFile', FileType::class, [
                'required' => false,
                'mapped' => false,
            ]);

        $hideFields = $options['hide_fields'] ?? [];
        foreach ($hideFields as $fieldName) {
            if ($builder->has($fieldName)) {
                $builder->remove($fieldName);
            }
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'hide_fields' => [],
            'readonly_fields' => [],
            'google_signup' => false,
        ]);

        $resolver->setAllowedTypes('hide_fields', 'array');
        $resolver->setAllowedTypes('readonly_fields', 'array');
        $resolver->setAllowedTypes('google_signup', 'bool');
    }
}