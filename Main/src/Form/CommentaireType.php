<?php

namespace App\Form;

use App\Entity\Commentaire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

class CommentaireType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('contenu', TextareaType::class, [
                'label' => 'Votre commentaire',
                'attr' => ['rows' => 3, 'placeholder' => 'Écrire un commentaire...']
            ])
            ->add('est_anonyme', CheckboxType::class, [
                'label' => 'Publier en anonyme',
                'required' => false
            ])
            ->add('parametres_confidentialite', ChoiceType::class, [
                'label' => 'Confidentialité',
                'choices' => [
                    'Public' => 'PUBLIC',
                    'Privé' => 'PRIVE',
                    'Amis' => 'AMIS',
                ]
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Commentaire::class,
        ]);
    }
}
