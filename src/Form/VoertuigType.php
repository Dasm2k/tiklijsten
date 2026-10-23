<?php

namespace App\Form;

use App\Entity\Voertuig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class VoertuigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('post', \Symfony\Bridge\Doctrine\Form\Type\EntityType::class, [
                'class' => \App\Entity\Post::class, 'choice_label' => 'naam',
                'placeholder' => 'Kies een post', 'required' => false,
                'help' => 'Zonder post is het voertuig niet beschikbaar voor chauffeursregistratie.',
                'query_builder' => fn ($r) => $r->createQueryBuilder('p')->orderBy('p.naam'),
            ])
            ->add('actief', CheckboxType::class, ['required' => false, 'label' => 'Actief (beschikbaar voor chauffeursregistratie)'])
            ->add('naam', TextType::class, ['help' => 'Merk en/of type'])
            ->add('roepnaam', TextType::class, ['required' => false])
            ->add('nummerplaat', TextType::class, ['required' => false])
            ->add('keuring', DateType::class, ['label' => 'Vervaldatum keuring', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Voertuig::class]);
    }
}
