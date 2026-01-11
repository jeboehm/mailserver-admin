<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Security\User\OAuth;

use App\Service\Security\User\OAuth\DenyRegistrationSubscriber;
use HWI\Bundle\OAuthBundle\Event\GetResponseUserEvent;
use HWI\Bundle\OAuthBundle\HWIOAuthEvents;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;

class DenyRegistrationSubscriberTest extends TestCase
{
    public function testGetSubscribedEvents(): void
    {
        $events = DenyRegistrationSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(HWIOAuthEvents::REGISTRATION_INITIALIZE, $events);
        $this->assertEquals('onRegistrationInitialize', $events[HWIOAuthEvents::REGISTRATION_INITIALIZE]);
    }

    public function testOnRegistrationInitializeWhenOAuthDisabled(): void
    {
        $twig = $this->createTwigMock();
        $subscriber = new DenyRegistrationSubscriber(
            enabled: false,
            canCreateUser: true,
            twig: $twig,
        );

        $renderedContent = '<html>Error page</html>';

        $twig
            ->expects($this->once())
            ->method('render')
            ->with('admin/oauth/error.html.twig')
            ->willReturn($renderedContent);

        $event = $this->createEvent();
        $subscriber->onRegistrationInitialize($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($renderedContent, $response->getContent());
        $this->assertEquals(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testOnRegistrationInitializeWhenCannotCreateUser(): void
    {
        $twig = $this->createTwigMock();
        $subscriber = new DenyRegistrationSubscriber(
            enabled: true,
            canCreateUser: false,
            twig: $twig,
        );

        $renderedContent = '<html>Error page</html>';

        $twig
            ->expects($this->once())
            ->method('render')
            ->with('admin/oauth/error.html.twig')
            ->willReturn($renderedContent);

        $event = $this->createEvent();
        $subscriber->onRegistrationInitialize($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($renderedContent, $response->getContent());
        $this->assertEquals(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testOnRegistrationInitializeWhenBothDisabled(): void
    {
        $twig = $this->createTwigMock();
        $subscriber = new DenyRegistrationSubscriber(
            enabled: false,
            canCreateUser: false,
            twig: $twig,
        );

        $renderedContent = '<html>Error page</html>';

        $twig
            ->expects($this->once())
            ->method('render')
            ->with('admin/oauth/error.html.twig')
            ->willReturn($renderedContent);

        $event = $this->createEvent();
        $subscriber->onRegistrationInitialize($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($renderedContent, $response->getContent());
        $this->assertEquals(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testOnRegistrationInitializeWhenBothEnabled(): void
    {
        $twig = $this->createTwigMock();
        $subscriber = new DenyRegistrationSubscriber(
            enabled: true,
            canCreateUser: true,
            twig: $twig,
        );

        $twig
            ->expects($this->never())
            ->method('render');

        $event = $this->createEvent();
        $subscriber->onRegistrationInitialize($event);

        $this->assertNull($event->getResponse());
    }

    private function createEvent(): GetResponseUserEvent
    {
        $user = $this->createStub(UserInterface::class);
        $request = new Request();

        return new GetResponseUserEvent($user, $request);
    }

    private function createTwigMock(): MockObject&Environment
    {
        return $this->createMock(Environment::class);
    }
}
