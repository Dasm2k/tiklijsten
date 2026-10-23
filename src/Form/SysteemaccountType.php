<?php

namespace App\Form;

use App\Entity\Afdeling;
use App\Entity\Persoon;
use App\Entity\Post;
use App\Enum\Rol;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Tik-pc's van de posten en het administratoraccount. */
final class SysteemaccountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Afdeling $afdeling */
        $afdeling = $options['afdeling'];
        $builder
            ->add('actief', \Symfony\Component\Form\Extension\Core\Type\CheckboxType::class, ['label' => 'Account actief', 'required' => false])
            ->add('naam', TextType::class, ['label' => 'Naam (bv. "Boom")'])
            ->add('voornaam', TextType::class, ['label' => 'Omschrijving (bv. "Tik-pc")'])
            ->add('tiknummer', IntegerType::class, ['label' => 'Aanmeldnummer'])
            ->add('nieuweCode', CodeType::class, ['mapped' => false, 'required' => $options['code_verplicht']])
            ->add('rol', EnumType::class, [
                'class' => Rol::class,
                'choice_label' => fn (Rol $r) => $r->label(),
                'choice_filter' => fn (?Rol $r) => null === $r || Rol::POST === $r || (Rol::ADMIN === $r && $options['admin_toegestaan']),
            ])
            ->add('post', EntityType::class, [
                'class' => Post::class,
                'choice_label' => 'naam',
                'query_builder' => fn ($r) => $r->createQueryBuilder('p')->orderBy('p.naam'),
                'placeholder' => '—',
                'required' => false,
                'help' => 'Voor een tik-pc: de post waarvoor lijsten gemaakt worden.',
            ])
            ->add('vastPeloton', ChoiceType::class, [
                'label' => 'Vast peloton',
                'choices' => array_combine(array_map(fn ($p) => $p.'e peloton', $afdeling->getPelotons()), $afdeling->getPelotons()),
                'placeholder' => 'Kiezen bij elke lijst',
                'required' => false,
            ])
            ->add('email', EmailType::class, ['required' => false, 'label' => 'E-mail'])
            ->add('sessieduur', IntegerType::class, [
                'label' => 'Sessieduur (minuten)',
                'required' => false,
                'help' => 'Leeg = instelling van de afdeling, 0 = nooit automatisch afmelden (aanbevolen voor een tik-pc).',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Persoon::class, 'code_verplicht' => false, 'admin_toegestaan' => false]);
        $resolver->setRequired('afdeling');
    }
}
