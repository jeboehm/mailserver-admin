<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Security\User\OAuth;

use App\Entity\User;
use App\Exception\DomainNotFoundException;
use App\Factory\UserFactory;
use App\Service\Security\User\OAuth\RegistrationFormHandler;
use HWI\Bundle\OAuthBundle\OAuth\Response\PathUserResponse;
use HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

class RegistrationFormHandlerTest extends TestCase
{
    private MockObject&UserFactory $userFactory;
    private MockObject&LoggerInterface $logger;
    private MockObject&FormInterface $form;
    private MockObject&PathUserResponse $userInformation;
    private RegistrationFormHandler $subject;

    protected function setUp(): void
    {
        $this->userFactory = $this->createMock(UserFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->form = $this->createMock(FormInterface::class);
        $this->userInformation = $this->createMock(PathUserResponse::class);
        $this->subject = new RegistrationFormHandler($this->userFactory, $this->logger);
    }

    public function testProcessWithValidEmailAndFormSubmittedAndValid(): void
    {
        $emailAddress = 'user@example.com';
        $user = $this->createStub(User::class);
        $request = $this->createStub(Request::class);

        $this->userInformation
            ->expects($this->once())
            ->method('getUserIdentifier')
            ->willReturn($emailAddress);

        $this->userInformation->expects($this->never())->method('getUsername');

        $this->userFactory
            ->expects($this->once())
            ->method('createFromEmailAddress')
            ->with($emailAddress)
            ->willReturn($user);

        $this->form
            ->expects($this->once())
            ->method('setData')
            ->with($user);

        $this->form
            ->expects($this->once())
            ->method('handleRequest')
            ->with($request);

        $this->form
            ->expects($this->once())
            ->method('isSubmitted')
            ->willReturn(true);

        $this->form
            ->expects($this->once())
            ->method('isValid')
            ->willReturn(true);

        $this->logger->expects($this->never())->method('error');

        $result = $this->subject->process($request, $this->form, $this->userInformation);

        $this->assertTrue($result);
    }

    public function testProcessWithValidEmailAndFormNotSubmitted(): void
    {
        $emailAddress = 'user@example.com';
        $user = $this->createStub(User::class);
        $request = $this->createStub(Request::class);

        $this->userInformation
            ->expects($this->once())
            ->method('getUserIdentifier')
            ->willReturn($emailAddress);

        $this->userInformation->expects($this->never())->method('getUsername');

        $this->userFactory
            ->expects($this->once())
            ->method('createFromEmailAddress')
            ->with($emailAddress)
            ->willReturn($user);

        $this->form
            ->expects($this->once())
            ->method('setData')
            ->with($user);

        $this->form
            ->expects($this->once())
            ->method('handleRequest')
            ->with($request);

        $this->form
            ->expects($this->once())
            ->method('isSubmitted')
            ->willReturn(false);

        $this->form->expects($this->never())->method('isValid');
        $this->logger->expects($this->never())->method('error');

        $result = $this->subject->process($request, $this->form, $this->userInformation);

        $this->assertFalse($result);
    }

    public function testProcessWithValidEmailAndFormSubmittedButInvalid(): void
    {
        $emailAddress = 'user@example.com';
        $user = $this->createStub(User::class);
        $request = $this->createStub(Request::class);

        $this->userInformation
            ->expects($this->once())
            ->method('getUserIdentifier')
            ->willReturn($emailAddress);

        $this->userInformation->expects($this->never())->method('getUsername');

        $this->userFactory
            ->expects($this->once())
            ->method('createFromEmailAddress')
            ->with($emailAddress)
            ->willReturn($user);

        $this->form
            ->expects($this->once())
            ->method('setData')
            ->with($user);

        $this->form
            ->expects($this->once())
            ->method('handleRequest')
            ->with($request);

        $this->form
            ->expects($this->once())
            ->method('isSubmitted')
            ->willReturn(true);

        $this->form
            ->expects($this->once())
            ->method('isValid')
            ->willReturn(false);

        $this->logger->expects($this->never())->method('error');

        $result = $this->subject->process($request, $this->form, $this->userInformation);

        $this->assertFalse($result);
    }

    public function testProcessThrowsExceptionForInvalidEmail(): void
    {
        $invalidEmail = 'not-an-email';
        $request = $this->createStub(Request::class);

        $this->userInformation
            ->expects($this->once())
            ->method('getUserIdentifier')
            ->willReturn($invalidEmail);

        $this->userInformation->expects($this->never())->method('getUsername');

        $this->userFactory
            ->expects($this->never())
            ->method('createFromEmailAddress');

        $this->form->expects($this->never())->method('setData');
        $this->form->expects($this->never())->method('handleRequest');
        $this->form->expects($this->never())->method('isSubmitted');
        $this->form->expects($this->never())->method('isValid');
        $this->logger->expects($this->never())->method('error');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No email address found in OAuth response. Check your OAUTH_PATHS_IDENTIFIER setting.');

        $this->subject->process($request, $this->form, $this->userInformation);
    }

    public function testProcessReturnsFalseWhenDomainNotFound(): void
    {
        $emailAddress = 'user@nonexistent.com';
        $request = $this->createStub(Request::class);

        $this->userInformation
            ->expects($this->once())
            ->method('getUserIdentifier')
            ->willReturn($emailAddress);

        $this->userInformation->expects($this->never())->method('getUsername');

        $this->userFactory
            ->expects($this->once())
            ->method('createFromEmailAddress')
            ->with($emailAddress)
            ->willThrowException(DomainNotFoundException::fromDomainName('nonexistent.com'));

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                'No domain found for email address from OAuth provider: {emailAddress}',
                ['emailAddress' => $emailAddress]
            );

        $this->form->expects($this->never())->method('setData');
        $this->form->expects($this->never())->method('handleRequest');
        $this->form->expects($this->never())->method('isSubmitted');
        $this->form->expects($this->never())->method('isValid');

        $result = $this->subject->process($request, $this->form, $this->userInformation);

        $this->assertFalse($result);
    }

    public function testProcessUsesGetUsernameWhenGetUserIdentifierNotAvailable(): void
    {
        $emailAddress = 'user@example.com';
        $user = $this->createStub(User::class);
        $request = $this->createStub(Request::class);

        $this->userInformation
            ->expects($this->never())
            ->method('getUserIdentifier');

        // Create a stub that implements UserResponseInterface but doesn't have getUserIdentifier
        // Using createStub and only configuring getUsername to simulate older OAuth response objects
        $userResponse = $this->createStub(UserResponseInterface::class);
        $userResponse->method('getUsername')->willReturn($emailAddress);

        $this->userFactory
            ->expects($this->once())
            ->method('createFromEmailAddress')
            ->with($emailAddress)
            ->willReturn($user);

        $this->form
            ->expects($this->once())
            ->method('setData')
            ->with($user);

        $this->form
            ->expects($this->once())
            ->method('handleRequest')
            ->with($request);

        $this->form
            ->expects($this->once())
            ->method('isSubmitted')
            ->willReturn(true);

        $this->form
            ->expects($this->once())
            ->method('isValid')
            ->willReturn(true);

        $this->logger->expects($this->never())->method('error');

        $result = $this->subject->process($request, $this->form, $userResponse);

        $this->assertTrue($result);
    }

    #[DataProvider('invalidEmailProvider')]
    public function testProcessThrowsExceptionForVariousInvalidEmails(string $invalidEmail): void
    {
        $request = $this->createStub(Request::class);

        $this->userInformation
            ->expects($this->once())
            ->method('getUserIdentifier')
            ->willReturn($invalidEmail);

        $this->userInformation->expects($this->never())->method('getUsername');

        $this->userFactory
            ->expects($this->never())
            ->method('createFromEmailAddress');

        $this->form->expects($this->never())->method('setData');
        $this->form->expects($this->never())->method('handleRequest');
        $this->form->expects($this->never())->method('isSubmitted');
        $this->form->expects($this->never())->method('isValid');
        $this->logger->expects($this->never())->method('error');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No email address found in OAuth response. Check your OAUTH_PATHS_IDENTIFIER setting.');

        $this->subject->process($request, $this->form, $this->userInformation);
    }

    public static function invalidEmailProvider(): array
    {
        return [
            'empty string' => [''],
            'no @ symbol' => ['notanemail'],
            'no domain' => ['user@'],
            'no user' => ['@example.com'],
            'multiple @ symbols' => ['user@@example.com'],
            'invalid characters' => ['user@exam ple.com'],
            'no TLD' => ['user@example'],
            'spaces' => ['user @example.com'],
        ];
    }
}
