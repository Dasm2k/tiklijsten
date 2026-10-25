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
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class LijstType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Afdeling $afdeling */
        $afdeling = $options['afdeling'];

        if ($options['soort_kiezen']) {
            $builder->add('speciaal', \Symfony\Component\Form\Extension\Core\Type\CheckboxType::class, [
                'label' => 'Speciale lijst voor een langdurige interventie', 'required' => false,
                'attr' => ['data-speciale-lijst' => '1'],
                'help' => 'Intikken registreert alleen het begin. Einduren vul je in via Aanwezigen. Een bevelvoerder is niet nodig.',
            ]);
        }
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
                'choice_attr' => static fn (InterventieType $type) => ['data-interventienummer-verplicht' => $type->isInterventienummerVerplicht() ? '1' : '0'],
                'attr' => ['data-interventietype' => '1'],
                'placeholder' => '— kies —',
            ])
            ->add('interventienummer', InterventienummerType::class)
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
                ]);
        }
        $builder->add('info', TextType::class, [
            'label' => 'Extra info',
            'required' => true,
            'empty_data' => '',
            'attr' => ['maxlength' => 100, 'placeholder' => 'Verplicht, max. 100 tekens'],
        ]);
        $bevelvoerder = static function (\Symfony\Component\Form\FormInterface $form, bool $speciaal) use ($afdeling, $options): void {
            $form->add('bevelvoerder', EntityType::class, [
                'class' => Persoon::class,
                'query_builder' => fn (PersoonRepository $r) => $r->keuzeQuery($afdeling),
                'choice_label' => fn (Persoon $p) => $p->getVolledigeNaam().' '.$p->getRang()?->getKort(),
                'placeholder' => '— kies —', 'attr' => ['data-zoekbaar' => '1'],
                // New forms validate the conditional requirement on the server too,
                // so a cached or unavailable script cannot block a special list.
                'required' => !$speciaal && !$options['soort_kiezen'], 'disabled' => $speciaal,
            ]);
        };
        $builder->addEventListener(\Symfony\Component\Form\FormEvents::PRE_SET_DATA, static function (\Symfony\Component\Form\FormEvent $event) use ($bevelvoerder): void {
            $bevelvoerder($event->getForm(), $event->getData()?->isSpeciaal() ?? false);
        });
        $builder->addEventListener(\Symfony\Component\Form\FormEvents::PRE_SUBMIT, static function (\Symfony\Component\Form\FormEvent $event) use ($bevelvoerder, $options): void {
            $data = $event->getData();
            $speciaal = $options['soort_kiezen'] ? !empty($data['speciaal']) : $event->getForm()->getData()->isSpeciaal();
            $bevelvoerder($event->getForm(), $speciaal);
        });
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $view->children['interventienummer']->vars['required'] = $form->getData()?->isInterventienummerVerplicht() ?? false;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Aanwezigheidslijst::class,
            'peloton_kiezen' => true,
            'post_kiezen' => false,
            'volledig' => false,
            'soort_kiezen' => false,
        ]);
        $resolver->setRequired('afdeling');
        $resolver->setAllowedTypes('afdeling', Afdeling::class);
    }
}
