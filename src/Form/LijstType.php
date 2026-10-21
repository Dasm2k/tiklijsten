<?php

namespace App\Form;

use App\Entity\Aanwezigheidslijst;
use App\Entity\Afdeling;
use App\Entity\Gemeente;
use App\Entity\InterventieType;
use App\Entity\Persoon;
use App\Entity\Post;
use App\Repository\GemeenteRepository;
use App\Repository\InterventieTypeRepository;
use App\Repository\PersoonRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class LijstType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Afdeling $afdeling */
        $afdeling = $options['afdeling'];

        $builder
            ->add('start', DateTimeType::class, [
                'label' => 'Begin interventie',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('gemeente', EntityType::class, [
                'class' => Gemeente::class,
                'query_builder' => fn (GemeenteRepository $r) => $r->keuzeQuery($afdeling),
                'choice_label' => 'naam',
                'placeholder' => '— kies —',
            ])
            ->add('interventieType', EntityType::class, [
                'label' => 'Interventie',
                'class' => InterventieType::class,
                'query_builder' => fn (InterventieTypeRepository $r) => $r->keuzeQuery($afdeling),
                'choice_label' => 'naam',
                'placeholder' => '— kies —',
            ])
            ->add('bevelvoerder', EntityType::class, [
                'class' => Persoon::class,
                'query_builder' => fn (PersoonRepository $r) => $r->keuzeQuery($afdeling),
                'choice_label' => fn (Persoon $p) => $p->getVolledigeNaam().' '.$p->getRang()?->getKort(),
                'placeholder' => '— kies —',
                'attr' => ['data-zoekbaar' => '1'],
            ]);

        if ($options['peloton_kiezen']) {
            $builder->add('peloton', ChoiceType::class, [
                'choices' => array_combine(array_map(fn ($p) => $p.'e peloton', $afdeling->getPelotons()), $afdeling->getPelotons()),
                'placeholder' => '— kies —',
                'label' => 'Peloton van wacht',
            ]);
        }
        if ($options['post_kiezen']) {
            $builder->add('post', EntityType::class, [
                'class' => Post::class,
                'choice_label' => 'naam',
                'placeholder' => '— kies —',
                'required' => true,
            ]);
        }
        if ($options['volledig']) {
            $builder
                ->add('tweedeStart', DateTimeType::class, [
                    'label' => 'Tweede beginuur',
                    'widget' => 'single_text',
                    'input' => 'datetime_immutable',
                    'required' => false,
                ])
                ->add('centralist', EntityType::class, [
                    'class' => Persoon::class,
                    'query_builder' => fn (PersoonRepository $r) => $r->keuzeQuery($afdeling),
                    'choice_label' => fn (Persoon $p) => $p->getVolledigeNaam().' '.$p->getRang()?->getKort(),
                    'placeholder' => 'n.v.t.',
                    'required' => false,
                ]);
        }
        $builder->add('info', TextType::class, [
            'label' => 'Extra info',
            'required' => false,
            'attr' => ['maxlength' => 100, 'placeholder' => 'Optioneel, max. 100 tekens'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Aanwezigheidslijst::class,
            'peloton_kiezen' => true,
            'post_kiezen' => false,
            'volledig' => false,
        ]);
        $resolver->setRequired('afdeling');
        $resolver->setAllowedTypes('afdeling', Afdeling::class);
    }
}
