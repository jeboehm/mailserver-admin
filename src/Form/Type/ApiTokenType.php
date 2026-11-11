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
use App\Service\ApiToken\SenderMode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The sender fields only matter for a token with mail:send or mail:draft. The page shows them only
 * then, and the controller ignores them otherwise, whatever was submitted.
 *
 * @extends AbstractType<array{name: string, scopes: list<ApiTokenScope>, senderMode: SenderMode, senderAddress: ?string, lifetimeDays: int}>
 */
class ApiTokenType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $maxLifetimeDays = $options['max_lifetime_days'];
        \assert(\is_int($maxLifetimeDays));
        /** @var list<string> $ownedAddresses */
        $ownedAddresses = $options['owned_addresses'];

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
                'choice_attr' => static fn (ApiTokenScope $scope): array => [
                    'data-api-token-sender-target' => 'scope',
                    'data-action' => 'change->api-token-sender#toggle',
                    'data-sends-mail' => \in_array($scope, ApiTokenScope::SENDER_BOUND, true) ? 'true' : 'false',
                ],
            ])
            ->add('senderMode', EnumType::class, [
                'label' => 'Sender',
                'class' => SenderMode::class,
                'expanded' => true,
                'choice_label' => static fn (SenderMode $mode): string => match ($mode) {
                    SenderMode::Primary => 'Primary address only',
                    SenderMode::Fixed => 'A fixed address',
                    SenderMode::Any => 'Any of my addresses',
                },
                'choice_attr' => static fn (): array => ['data-action' => 'change->api-token-sender#toggle'],
                'help' => 'The address the token sends and drafts mail as. It cannot be changed later.',
                'row_attr' => ['data-api-token-sender-target' => 'sender'],
                'constraints' => [new NotBlank()],
            ])
            ->add('senderAddress', ChoiceType::class, [
                'label' => 'Fixed address',
                'choices' => array_combine($ownedAddresses, $ownedAddresses),
                'placeholder' => false,
                'required' => false,
                'help' => 'Your primary address or one of the aliases that point at it.',
                'row_attr' => ['data-api-token-sender-target' => 'address'],
            ])
            ->add('lifetimeDays', IntegerType::class, [
                'label' => 'Valid for (days)',
                'help' => \sprintf('At most %d days.', $maxLifetimeDays),
                'attr' => ['min' => 1, 'max' => $maxLifetimeDays],
                'constraints' => [new NotBlank(), new Range(min: 1, max: $maxLifetimeDays)],
            ]);
    }

    /**
     * The address itself is checked by its choices, which are the owned addresses.
     */
    public static function validateFixedSender(mixed $data, ExecutionContextInterface $context): void
    {
        if (!\is_array($data) || SenderMode::Fixed !== ($data['senderMode'] ?? null) || null !== ($data['senderAddress'] ?? null)) {
            return;
        }

        $scopes = \is_array($data['scopes'] ?? null) ? $data['scopes'] : [];

        if (array_any($scopes, static fn (mixed $scope): bool => \in_array($scope, ApiTokenScope::SENDER_BOUND, true))) {
            $context->buildViolation('Choose the address the token is bound to.')->atPath('[senderAddress]')->addViolation();
        }
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['max_lifetime_days', 'owned_addresses']);
        $resolver->setAllowedTypes('max_lifetime_days', 'int');
        $resolver->setAllowedTypes('owned_addresses', 'string[]');
        $resolver->setDefault('attr', ['data-controller' => 'api-token-sender']);
        $resolver->setDefault('constraints', [new Callback(self::validateFixedSender(...))]);
    }
}
