<?php

namespace App\Form;

use App\Entity\Afdeling;
use App\Entity\Mailinglijst;
use App\Entity\Persoon;
use App\Repository\PersoonRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class MailinglijstType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Afdeling $afdeling */
        $afdeling = $options['afdeling'];
        $builder
            ->add('naam', TextType::class)
            ->add('omschrijving', TextareaType::class, ['required' => false, 'attr' => ['rows' => 2]])
            ->add('peloton', ChoiceType::class, [
                'label' => 'Ontvangt afgesloten lijsten van',
                'choices' => array_combine(array_map(fn ($p) => $p.'e peloton', $afdeling->getPelotons()), $afdeling->getPelotons()),
                'placeholder' => '— geen peloton —',
                'required' => false,
            ])
            ->add('alleLijsten', CheckboxType::class, ['label' => 'Ontvangt alle afgesloten lijsten', 'required' => false])
            ->add('foutmeldingen', CheckboxType::class, ['label' => 'Ontvangt nieuwe foutmeldingen', 'required' => false])
            ->add('leden', EntityType::class, [
                'class' => Persoon::class,
                'query_builder' => fn (PersoonRepository $r) => $r->keuzeQuery($afdeling),
                'choice_label' => fn (Persoon $p) => $p->getVolledigeNaam(),
                'choice_attr' => fn (Persoon $p) => $p->getEmail() ? [] : ['disabled' => 'disabled', 'title' => 'Geen e-mailadres'],
                'group_by' => fn (Persoon $p) => $p->getPeloton() ? $p->getPeloton().'e peloton' : 'Niet ingedeeld',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'by_reference' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Mailinglijst::class]);
        $resolver->setRequired('afdeling');
    }
}
