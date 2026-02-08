<?php

namespace App\Form;

use App\Entity\Reclamation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Validator\Constraints\File;


class ReclamationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
{
    $builder
        ->add('contenu')
        ->add('description')
        ->add('pieceJointePath', FileType::class, [
    'label' => 'Pièce jointe (optionnelle)',
    'mapped' => false, // ⚠️ IMPORTANT
    'required' => false,
    'constraints' => [
        new File([
            'maxSize' => '5M',
            'mimeTypes' => [
                'application/pdf',
                'image/jpeg',
                'image/png',
            ],
            'mimeTypesMessage' => 'Veuillez uploader un PDF, JPG ou PNG',
        ])
    ],
])

        ->add('type', ChoiceType::class, [
    'choices' => [
        'Rendez-vous' => 'RENDEZ_VOUS',
        'Retard / attente excessive' => 'RETARD_ATTENTE',
        'Personnel médical' => 'PERSONNEL_MEDICAL',
        'Personnel administratif' => 'PERSONNEL_ADMIN',
        'Facturation / paiement' => 'FACTURATION',
        'Assurance / prise en charge' => 'ASSURANCE',
        'Service d’urgence' => 'URGENCE',
        'Hospitalisation' => 'HOSPITALISATION',
        'Qualité des soins' => 'QUALITE_SOINS',
        'Erreur médicale' => 'ERREUR_MEDICALE',
        'Dossier médical' => 'DOSSIER_MEDICAL',
        'Pharmacie / médicaments' => 'PHARMACIE',
        'Laboratoire / analyses' => 'LABORATOIRE',
        'Radiologie / imagerie' => 'RADIOLOGIE',
        'Hygiène' => 'HYGIENE',
        'Sécurité' => 'SECURITE',
        'Infrastructure / équipement' => 'INFRASTRUCTURE',
        'Accueil / orientation' => 'ACCUEIL',
        'Autre' => 'AUTRE',
    ],
    'placeholder' => 'Choisir le type de réclamation',
    'required' => true,
    'attr' => [
        'class' => 'form-select'
    ]
])
;
}


    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Reclamation::class,
        ]);
    }
}
