<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\NotBlank;

final class CodeWijzigenType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('huidigeCode', PasswordType::class, [
                'label' => 'Huidige code',
                'constraints' => [new NotBlank(), new UserPassword(message: 'Je huidige code is niet juist.')],
            ])
            ->add('nieuweCode', CodeType::class, ['required' => true, 'constraints' => [
                new NotBlank(),
                new \Symfony\Component\Validator\Constraints\Regex(pattern: '/^\d{4,12}$/', message: 'De code moet uit minstens 4 cijfers bestaan.'),
            ]]);
    }
}
