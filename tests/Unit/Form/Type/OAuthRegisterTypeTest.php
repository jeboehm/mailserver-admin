<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Form\Type;

use App\Entity\User;
use App\Form\Type\OAuthRegisterType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class OAuthRegisterTypeTest extends TestCase
{
    private OAuthRegisterType $formType;

    protected function setUp(): void
    {
        $this->formType = new OAuthRegisterType();
    }

    public function testConfigureOptions(): void
    {
        $resolver = $this->createMock(OptionsResolver::class);

        $resolver
            ->expects($this->once())
            ->method('setDefaults')
            ->with([
                'data_class' => User::class,
                'validation_groups' => ['register'],
            ]);

        $this->formType->configureOptions($resolver);
    }

    public function testBuildForm(): void
    {
        $builder = $this->createMock(FormBuilderInterface::class);

        $builder
            ->expects($this->once())
            ->method('add')
            ->with(
                'plainPassword',
                RepeatedType::class,
                $this->callback(function (array $options): bool {
                    return PasswordType::class === $options['type']
                        && true === $options['required']
                        && isset($options['first_options']['label'])
                        && 'New password' === $options['first_options']['label']
                        && isset($options['second_options']['label'])
                        && 'Repeat new password' === $options['second_options']['label'];
                })
            )
            ->willReturnSelf();

        $this->formType->buildForm($builder, []);
    }
}
