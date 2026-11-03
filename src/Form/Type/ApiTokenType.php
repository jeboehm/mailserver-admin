<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Form\Type;

use App\Service\ApiToken\ApiTokenScope;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

/**
 * @extends AbstractType<array{name: string, scopes: list<ApiTokenScope>, lifetimeDays: int}>
 */
class ApiTokenType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $maxLifetimeDays = $options['max_lifetime_days'];
        \assert(\is_int($maxLifetimeDays));

        $builder
            ->add('name', TextType::class, [
                'help' => 'Helps you tell your tokens apart, for example the name of the agent using it.',
                'constraints' => [new NotBlank(), new Length(max: 100)],
            ])
            ->add('scopes', EnumType::class, [
                'class' => ApiTokenScope::class,
                'choice_label' => static fn (ApiTokenScope $scope): string => \sprintf('%s (%s)', $scope->description(), $scope->value),
                'multiple' => true,
                'expanded' => true,
                'help' => 'What a token can do is also limited by your role.',
                'constraints' => [new Count(min: 1, minMessage: 'Choose at least one scope.')],
            ])
            ->add('lifetimeDays', IntegerType::class, [
                'label' => 'Valid for (days)',
                'help' => \sprintf('At most %d days.', $maxLifetimeDays),
                'attr' => ['min' => 1, 'max' => $maxLifetimeDays],
                'constraints' => [new NotBlank(), new Range(min: 1, max: $maxLifetimeDays)],
            ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('max_lifetime_days');
        $resolver->setAllowedTypes('max_lifetime_days', 'int');
    }
}
