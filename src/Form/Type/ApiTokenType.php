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

class ApiTokenType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'help' => 'Helps you tell your tokens apart, for example the name of the agent using it.',
                'constraints' => [new NotBlank(), new Length(max: 100)],
            ])
            ->add('scopes', EnumType::class, [
                'class' => ApiTokenScope::class,
                'choice_label' => static fn (ApiTokenScope $scope): string => match ($scope) {
                    ApiTokenScope::ManageRead => 'Read domains, users, aliases, fetchmail accounts and DKIM status; run DNS validation (manage:read)',
                    ApiTokenScope::ManageWrite => 'Create, update and delete the same (manage:write)',
                    ApiTokenScope::MailRead => 'Read your own mailbox (mail:read)',
                    ApiTokenScope::MailSend => 'Send mail from your own address (mail:send)',
                },
                'multiple' => true,
                'expanded' => true,
                'help' => 'What a token can do is also limited by your role.',
                'constraints' => [new Count(min: 1, minMessage: 'Choose at least one scope.')],
            ])
            ->add('lifetimeDays', IntegerType::class, [
                'label' => 'Valid for (days)',
                'help' => \sprintf('At most %d days.', $options['max_lifetime_days']),
                'attr' => ['min' => 1, 'max' => $options['max_lifetime_days']],
                'constraints' => [new NotBlank(), new Range(min: 1, max: $options['max_lifetime_days'])],
            ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('max_lifetime_days');
        $resolver->setAllowedTypes('max_lifetime_days', 'int');
    }
}
