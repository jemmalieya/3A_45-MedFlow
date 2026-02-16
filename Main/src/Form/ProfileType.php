<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;

class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('cin', TextType::class, [
                'disabled' => true, // CIN non modifiable (souvent)
                'required' => false,
            ])
            ->add('nom', TextType::class, ['required' => true])
            ->add('prenom', TextType::class, ['required' => true])
            ->add('dateNaissance', DateType::class, [
                'widget' => 'single_text',
                'required' => true,
            ])
            ->add('telephoneUser', TextType::class, ['required' => true])
            ->add('emailUser', EmailType::class, [
                'disabled' => true, // Email non modifiable (optionnel)
                'required' => false,
            ])
            ->add('adresseUser', TextType::class, [
                'required' => false,
            ])
            ->add('profilePictureFile', FileType::class, [
                'mapped' => false,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
