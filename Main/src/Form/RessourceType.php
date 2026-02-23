<?php

namespace App\Form;

use App\Entity\Evenement;
use App\Entity\Ressource;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;

class RessourceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('evenement', EntityType::class, [
                'class' => Evenement::class,
                'choice_label' => 'titreEvent',
                'label' => 'Événement',
                'placeholder' => 'Choisir un événement',
            ])

            ->add('nomRessource', null, [
                'label' => 'Nom de la ressource',
                'attr' => ['placeholder' => 'Ex: Agenda PDF / Badge / Projecteur'],
            ])

            ->add('categorieRessource', null, [
                'label' => 'Catégorie',
                'attr' => ['placeholder' => 'Ex: Document / Matériel / Stock'],
            ])

            ->add('typeRessource', ChoiceType::class, [
                'label' => 'Type',
                'choices' => [
                    'Fichier' => 'file',
                    'Lien externe' => 'external_link',
                    'Stock / Matériel' => 'stock_item',
                ],
                'attr' => ['class' => 'js-type-ressource'],
            ])

            // ✅ CHAMP UPLOAD Cloudinary (NON MAPPÉ)
            ->add('uploadFile', FileType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Fichier (upload)',
                'attr' => ['class' => 'js-upload-file'],
                'constraints' => [
                    new File([
                        'maxSize' => '10M',
                        // optionnel: limite types
                        // 'mimeTypes' => ['application/pdf', 'image/png', 'image/jpeg'],
                        'mimeTypesMessage' => 'Fichier invalide.',
                    ]),
                ],
            ])

            // ⚠️ Tu peux garder cheminFichierRessource mais en lecture seule si tu veux
            ->add('cheminFichierRessource', null, [
                'required' => false,
                'label' => 'Chemin fichier (auto)',
                'attr' => ['class' => 'js-field-file', 'readonly' => true],
            ])

            ->add('urlExterneRessource', null, [
                'required' => false,
                'label' => 'URL externe',
                'attr' => ['class' => 'js-field-link'],
            ])

            ->add('quantiteDisponibleRessource', IntegerType::class, [
                'required' => false,
                'label' => 'Quantité disponible',
                'attr' => [
                    'min' => 0,
                    'placeholder' => 'Ex: 50',
                ],
            ])

            ->add('uniteRessource', null, [
                'required' => false,
                'label' => 'Unité',
                'attr' => ['class' => 'js-field-stock'],
            ])

            ->add('fournisseurRessource', null, [
                'required' => false,
                'label' => 'Fournisseur',
                'attr' => ['class' => 'js-field-stock'],
            ])

            ->add('coutEstimeRessource', null, [
                'required' => false,
                'label' => 'Coût estimé',
                'attr' => ['class' => 'js-field-stock'],
            ])
            ->add('signatureData', HiddenType::class, [
               'mapped' => false,
               'required' => false,
          ])

            ->add('notesRessource', TextareaType::class, [
                'required' => false,
                'label' => 'Notes',
                'attr' => ['rows' => 4],
            ]);
                    // ✅ Validation dynamique: si type=file => uploadFile obligatoire (création)
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
            /** @var Ressource|null $ressource */
            $ressource = $event->getData();
            $form = $event->getForm();

            if (!$ressource) {
                return;
            }

            $uploaded = $form->get('uploadFile')->getData();

            // Si type=file et aucun fichier choisi et aucun chemin existant (cas edit)
            if ($ressource->getTypeRessource() === 'file'
                && !$uploaded
                && !$ressource->getCheminFichierRessource()
            ) {
                $form->get('uploadFile')->addError(new FormError('Veuillez choisir un fichier.'));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Ressource::class,
        ]);
    }
}