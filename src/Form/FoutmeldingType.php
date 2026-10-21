<?php

namespace App\Form;

use App\Entity\Afdeling;
use App\Entity\Voertuig;
use App\Enum\FoutType;
use App\Repository\VoertuigRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Constraints as Assert;

/** Nieuwe foutmelding. De velden onder "wat" hangen af van het gekozen type. */
final class FoutmeldingType extends AbstractType
{
    public const KLEDIJ = ['Helm', 'Interventiejas', 'Interventiebroek', 'Interventiehandschoenen', 'Laarzen', 'Nomexkap', 'Fluo hesje'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Afdeling $afdeling */
        $afdeling = $options['afdeling'];

        $builder
            ->add('type', EnumType::class, [
                'class' => FoutType::class,
                'label' => 'Type fout',
                'choice_label' => fn (FoutType $t) => $t->label(),
                'choice_filter' => fn (?FoutType $t) => null === $t || FoutType::KADAVER !== $t || [] !== $afdeling->getKadaverOntvangersLijst(),
                'placeholder' => '— kies —',
                'constraints' => [new Assert\NotNull(message: 'Kies het type fout.')],
                'attr' => ['data-fout-type' => '1'],
            ])
            ->add('voertuig', EntityType::class, [
                'class' => Voertuig::class,
                'query_builder' => fn (VoertuigRepository $r) => $r->createQueryBuilder('v')->andWhere('v.afdeling = :a')->setParameter('a', $afdeling)->orderBy('v.naam'),
                'choice_label' => fn (Voertuig $v) => trim($v->getNaam().($v->getRoepnaam() ? ' ('.$v->getRoepnaam().')' : '')),
                'placeholder' => 'Iets anders (hieronder invullen)',
                'required' => false,
                'row_attr' => ['data-voor-type' => '1'],
            ])
            ->add('wat', TextType::class, [
                'label' => 'Wat',
                'required' => false,
                'row_attr' => ['data-voor-type' => '1 2 50'],
                'attr' => ['maxlength' => 128],
            ])
            ->add('kledij', ChoiceType::class, [
                'choices' => array_combine(self::KLEDIJ, self::KLEDIJ),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'row_attr' => ['data-voor-type' => '3'],
            ])
            ->add('genummerd', CheckboxType::class, [
                'label' => 'Op elk kledingstuk staat mijn tiknummer (controleer dit!)',
                'required' => false,
                'row_attr' => ['data-voor-type' => '3'],
            ])
            ->add('soortKadaver', TextType::class, ['required' => false, 'row_attr' => ['data-voor-type' => '4']])
            ->add('locatieKadaver', TextType::class, ['label' => 'Waar is het kadaver opgeslagen?', 'required' => false, 'row_attr' => ['data-voor-type' => '4']])
            ->add('inOpdrachtVan', ChoiceType::class, [
                'choices' => ['Brandweer' => 'Brandweer', 'Politie' => 'Politie'],
                'expanded' => true,
                'data' => 'Brandweer',
                'row_attr' => ['data-voor-type' => '4'],
            ])
            ->add('probleem', TextareaType::class, [
                'label' => 'Omschrijving van het probleem',
                'attr' => ['rows' => 8],
                'constraints' => [new Assert\NotBlank(message: 'Omschrijf het probleem.')],
            ]);

        if ($options['email_vragen']) {
            $builder->add('email', EmailType::class, [
                'label' => 'Je e-mailadres',
                'help' => 'Zo blijf je op de hoogte van de opvolging.',
                'required' => false,
                'constraints' => [new Assert\Email()],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'email_vragen' => false,
            'constraints' => [new Assert\Callback(self::valideer(...))],
        ]);
        $resolver->setRequired('afdeling');
    }

    public static function valideer(array $data, ExecutionContextInterface $context): void
    {
        $type = $data['type'] ?? null;
        $fout = fn (string $veld, string $tekst) => $context->buildViolation($tekst)->atPath('['.$veld.']')->addViolation();
        match ($type) {
            FoutType::VOERTUIG => (!$data['voertuig'] && !trim((string) $data['wat'])) ? $fout('wat', 'Kies een voertuig of vul in over welk voertuig het gaat.') : null,
            FoutType::TOESTEL, FoutType::ANDERS => !trim((string) $data['wat']) ? $fout('wat', 'Vul in waarover het gaat.') : null,
            FoutType::KLEDIJ => !$data['kledij'] ? $fout('kledij', 'Duid minstens één kledingstuk aan.') : (!$data['genummerd'] ? $fout('genummerd', 'Controleer of al je kledij genummerd is en vink dit aan.') : null),
            FoutType::KADAVER => (!trim((string) $data['soortKadaver']) ? $fout('soortKadaver', 'Over welk soort kadaver gaat het?') : (!trim((string) $data['locatieKadaver']) ? $fout('locatieKadaver', 'Waar is het kadaver opgeslagen?') : null)),
            default => null,
        };
    }
}
