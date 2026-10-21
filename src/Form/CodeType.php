<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** Nieuwe (numerieke) code, twee keer in te geven. */
final class CodeType extends AbstractType
{
    public function getParent(): string
    {
        return RepeatedType::class;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'type' => PasswordType::class,
            'invalid_message' => 'De codes zijn niet gelijk.',
            'first_options' => ['label' => 'Nieuwe code', 'attr' => ['autocomplete' => 'new-password', 'inputmode' => 'numeric']],
            'second_options' => ['label' => 'Herhaal code', 'attr' => ['autocomplete' => 'new-password', 'inputmode' => 'numeric']],
            'constraints' => [
                new Assert\Regex(pattern: '/^\d{4,12}$/', message: 'De code moet uit minstens 4 cijfers bestaan.'),
            ],
        ]);
    }
}
