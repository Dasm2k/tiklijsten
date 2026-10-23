<?php

namespace App\Form;

use App\Entity\Afdeling;
use App\Entity\Persoon;
use App\Entity\Post;
use App\Entity\Rang;
use App\Enum\Rol;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PersoonType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Afdeling $afdeling */
        $afdeling = $options['afdeling'];
        /** @var Rol $maxRol */
        $maxRol = $options['max_rol'];

        $builder
            ->add('tiknummer', IntegerType::class)
            ->add('zonenummer', IntegerType::class, ['required' => false])
            ->add('nieuweCode', CodeType::class, ['mapped' => false, 'required' => $options['code_verplicht']])
            ->add('naam', TextType::class)
            ->add('voornaam', TextType::class)
            ->add('rang', EntityType::class, [
                'class' => Rang::class,
                'choice_label' => 'naam',
                'query_builder' => fn ($r) => $r->createQueryBuilder('r')->orderBy('r.niveau', 'DESC'),
                'placeholder' => '— kies —',
                'required' => true,
            ])
            ->add('peloton', ChoiceType::class, [
                'choices' => array_combine(array_map(fn ($p) => $p.'e peloton', $afdeling->getPelotons()), $afdeling->getPelotons()),
                'placeholder' => 'Niet ingedeeld',
                'required' => false,
            ])
            ->add('post', EntityType::class, [
                'class' => Post::class,
                'choice_label' => 'naam',
                'query_builder' => fn ($r) => $r->createQueryBuilder('p')->orderBy('p.naam'),
                'placeholder' => '—',
                'required' => false,
            ])
            ->add('email', EmailType::class, ['label' => 'E-mail', 'required' => false])
            ->add('beroeps', CheckboxType::class, ['label' => 'Beroepspersoneel', 'required' => false])
            ->add('sessieduur', IntegerType::class, [
                'label' => 'Sessieduur (minuten)',
                'required' => false,
                'help' => 'Leeg = instelling van de afdeling, 0 = nooit automatisch afmelden.',
            ])
            ->add('rol', EnumType::class, [
                'class' => Rol::class,
                'choice_label' => fn (Rol $r) => $r->label(),
                'choice_filter' => fn (?Rol $r) => null === $r || (Rol::SUPERADMIN !== $r && Rol::POST !== $r && $r->gewicht() <= $maxRol->gewicht()),
                'help' => 'Je kan geen hogere rol toekennen dan je eigen rol.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Persoon::class,
            'code_verplicht' => false,
            'max_rol' => Rol::LID,
        ]);
        $resolver->setRequired('afdeling');
    }
}
