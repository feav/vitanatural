<?php

namespace App\Form;

use App\Entity\Formule;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

class FormuleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('message')
            ->add('price')
            ->add('month', ChoiceType::class, [
                'choices'  => [
                    '1' => 1,
                    '12' => 12,
                ],
            ])
            ->add('name')
            ->add('price_shipping')
            ->add('try_days')
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => Formule::class,
        ]);
    }
}
