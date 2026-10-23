<?php

namespace App\Form;

use App\Entity\InterventieType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class InterventieTypeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('naam', TextType::class)
            ->add('volgorde', IntegerType::class)
            ->add('actief', CheckboxType::class, ['required' => false])
            ->add('inStatistiek', CheckboxType::class, ['label' => 'Telt mee in jaarstatistiek', 'required' => false])
            ->add('chauffeurVerplicht', CheckboxType::class, [
                'label' => 'Chauffeur verplicht bij afsluiten',
                'required' => false,
                'help' => 'Vink uit als lijsten van dit type zonder chauffeur afgesloten mogen worden. Een chauffeur registreren blijft mogelijk als chauffeursregistratie voor de post aanstaat.',
            ])
            ->add('interventienummerVerplicht', CheckboxType::class, [
                'label' => 'Interventienummer verplicht',
                'required' => false,
                'help' => 'Vereis een FrontForce-interventienummer bij het aanmaken, opslaan en afsluiten van een lijst van dit type.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => InterventieType::class]);
    }
}
