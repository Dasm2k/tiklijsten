<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Accepteert uitsluitend cijfers, zonder decimalen af te ronden. */
final class InterventienummerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?int $nummer): string => null === $nummer ? '' : (string) $nummer,
            static function (?string $waarde): ?int {
                if (null === $waarde || '' === $waarde) { return null; }
                if (!preg_match('/\A[0-9]{1,8}\z/', $waarde)) {
                    throw new TransformationFailedException('Een interventienummer bestaat uit maximaal 8 cijfers.');
                }
                return (int) $waarde;
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'label' => 'Interventie #',
            'required' => false,
            'invalid_message' => 'Vul een geheel getal van maximaal 8 cijfers in.',
            'attr' => [
                'placeholder' => 'Interventienummer FrontForce',
                'inputmode' => 'numeric',
                'maxlength' => 8,
                'pattern' => '[0-9]{1,8}',
                'data-interventienummer' => '1',
            ],
            'help' => 'Maximaal 8 cijfers. Verplicht als dit voor het gekozen interventietype is ingesteld.',
        ]);
    }

    public function getParent(): string { return TextType::class; }
}
