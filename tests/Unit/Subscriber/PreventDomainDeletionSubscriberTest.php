<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Subscriber;

use App\Entity\Alias;
use App\Entity\Domain;
use App\Entity\User;
use App\Subscriber\PreventDomainDeletionSubscriber;
use Doctrine\ORM\Mapping\ClassMetadata;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Context\CrudContext;
use EasyCorp\Bundle\EasyAdminBundle\Context\RequestContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\CrudDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeCrudActionEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\RouterInterface;

class PreventDomainDeletionSubscriberTest extends TestCase
{
    private MockObject&RouterInterface $router;
    private MockObject&FlashBagAwareSessionInterface $session;
    private PreventDomainDeletionSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->router = $this->createMock(RouterInterface::class);
        $this->session = $this->createMock(FlashBagAwareSessionInterface::class);
        $this->subscriber = new PreventDomainDeletionSubscriber($this->router);
    }

    public function testGetSubscribedEvents(): void
    {
        $events = PreventDomainDeletionSubscriber::getSubscribedEvents();

        $this->router->expects($this->never())->method('generate');
        $this->session->expects($this->never())->method('getFlashBag');

        $this->assertArrayHasKey(BeforeCrudActionEvent::class, $events);
        $this->assertEquals('onBeforeCrudAction', $events[BeforeCrudActionEvent::class]);
    }

    public function testDomainDeletionPreventionLogic(): void
    {
        $domain = new Domain();
        $domain->getUsers()->add(new User());
        $domain->getAliases()->add(new Alias());

        $adminContext = $this->createContext($domain);
        $event = new BeforeCrudActionEvent($adminContext);

        $this->router
            ->expects($this->once())
            ->method('generate')
            ->with('admin_domain_index')
            ->willReturn('http://localhost/domain/');

        $flashBagMock = $this->createMock(FlashBagInterface::class);
        $flashBagMock
            ->expects($this->once())
            ->method('add')
            ->with('error', 'Error: This domain currently holds users and/or aliases. Please delete them first.');

        $this->session
            ->expects($this->once())
            ->method('getFlashBag')
            ->willReturn($flashBagMock);

        $this->subscriber->onBeforeCrudAction($event);
    }

    public function testDomainDeletionOk(): void
    {
        $domain = new Domain();

        $adminContext = $this->createContext($domain);
        $event = new BeforeCrudActionEvent($adminContext);

        $this->router
            ->expects($this->never())
            ->method('generate');

        $this->session
            ->expects($this->never())
            ->method('getFlashBag');

        $this->subscriber->onBeforeCrudAction($event);
    }

    private function createContext(Domain $domain): AdminContext
    {
        $crudDto = new CrudDto();
        $crudDto->setCurrentAction(Action::DELETE);
        $request = new Request();
        $request->setSession($this->session);

        return AdminContext::forTesting(
            requestContext: RequestContext::forTesting($request),
            crudContext: CrudContext::forTesting(
                $crudDto,
                new EntityDto(
                    Domain::class,
                    $this->createStub(ClassMetadata::class),
                    entityInstance: $domain
                )
            )
        );
    }
}
