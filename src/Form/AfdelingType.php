<?php

namespace App\Form;

use App\Entity\Afdeling;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class AfdelingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('naam', TextType::class, ['help' => 'Zoals getoond op de startpagina, bv. "Post Boom/Rumst".'])
            ->add('titel', TextType::class, ['help' => 'Titel in de browser en in e-mails, bv. "Brandweer Boom".'])
            ->add('footer', TextType::class, ['required' => false])
            ->add('sessieduur', IntegerType::class, ['label' => 'Sessieduur (minuten)', 'help' => 'Automatisch afmelden na inactiviteit. 0 = uitgeschakeld.'])
            ->add('aantalPelotons', IntegerType::class, ['label' => 'Aantal pelotons'])
            ->add('foutmeldingenActief', CheckboxType::class, ['label' => 'Module foutmeldingen', 'required' => false])
            ->add('voertuigenActief', CheckboxType::class, ['label' => 'Module voertuigen & materiaal', 'required' => false])
            ->add('kadaverOntvangers', TextType::class, [
                'label' => 'Ontvangers meldingen kadaver',
                'required' => false,
                'help' => 'E-mailadressen, gescheiden door komma\'s.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Afdeling::class]);
    }
}
