<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class BerichtType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('bericht', TextareaType::class, [
            'attr' => ['rows' => 8],
            'required' => $options['verplicht'],
            'constraints' => $options['verplicht'] ? [new Assert\NotBlank(message: 'Typ eerst een bericht.')] : [],
        ]);
        if ($options['iedereen_optie']) {
            $builder->add('iedereen', CheckboxType::class, [
                'label' => 'Iedereen mailen? (standaard krijgen enkel de melder en de onderofficieren en officieren een mail)',
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['verplicht' => true, 'iedereen_optie' => false]);
    }
}
