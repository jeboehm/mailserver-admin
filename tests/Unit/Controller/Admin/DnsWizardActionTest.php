<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Controller\Admin;

use App\Controller\Admin\DnsWizardAction;
use App\Entity\Domain;
use App\Entity\User;
use App\Repository\DomainRepository;
use App\Service\DnsWizard\DnsWizardValidator;
use App\Service\DnsWizard\ExpectedHostIps;
use App\Service\DnsWizard\HostIpResolver;
use App\Service\Security\Roles;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Twig\Environment;

class DnsWizardActionTest extends TestCase
{
    private MockObject|Environment $twig;
    private MockObject|DomainRepository $domainRepository;
    private MockObject|HostIpResolver $hostIpResolver;
    private MockObject|DnsWizardValidator $validator;
    private MockObject|Security $security;
    private MockObject|CacheInterface $cacheApp;

    protected function setUp(): void
    {
        $this->twig = $this->createMock(Environment::class);
        $this->domainRepository = $this->createMock(DomainRepository::class);
        $this->hostIpResolver = $this->createMock(HostIpResolver::class);
        $this->validator = $this->createMock(DnsWizardValidator::class);
        $this->security = $this->createMock(Security::class);
        $this->cacheApp = $this->createMock(CacheInterface::class);
    }

    public function testAdminSeesAllDomains(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');

        $expectedIps = new ExpectedHostIps(['1.2.3.4'], [], true);
        $result = ['mailHost' => [], 'domains' => []];

        $cacheItem = $this->createMock(ItemInterface::class);
        $cacheItem->expects($this->atLeastOnce())
            ->method('expiresAfter')
            ->with($this->isInstanceOf(\DateInterval::class));

        $this->security->expects($this->once())->method('isGranted')->with(Roles::ROLE_ADMIN)->willReturn(true);
        $this->domainRepository->expects($this->once())->method('findBy')->with([], ['name' => 'ASC'])->willReturn([$domain]);
        $this->hostIpResolver->expects($this->once())->method('resolveExpectedHostIps')->willReturn($expectedIps);
        $this->validator
            ->expects($this->atLeastOnce())
            ->method('validate')
            ->with('mail.example.com', $expectedIps, [$domain])
            ->willReturn($result);

        $this->cacheApp
            ->expects($this->once())
            ->method('get')
            ->with(
                $this->isString(),
                $this->callback(function (callable $callback) use ($cacheItem, $result) {
                    return $result === $callback($cacheItem);
                })
            )
            ->willReturnCallback(function (string $key, callable $callback) use ($cacheItem) {
                return $callback($cacheItem);
            });

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with('admin/dns_wizard/index.html.twig', $this->callback(static function (array $context) use ($expectedIps, $result) {
                return 'mail.example.com' === $context['mailname']
                    && $expectedIps === $context['expectedIps']
                    && $result === $context['result'];
            }))
            ->willReturn('html');

        $controller = new DnsWizardAction(
            twig: $this->twig,
            domainRepository: $this->domainRepository,
            hostIpResolver: $this->hostIpResolver,
            validator: $this->validator,
            security: $this->security,
            cacheApp: $this->cacheApp,
            mailname: 'mail.example.com',
        );

        $response = $controller(new Request());

        self::assertSame('html', $response->getContent());
    }

    public function testDomainAdminIsRestrictedToOwnDomain(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');

        $user = new User();
        $user->setDomain($domain);

        $expectedIps = new ExpectedHostIps(['1.2.3.4'], [], true);
        $result = ['mailHost' => [], 'domains' => []];

        $cacheItem = $this->createMock(ItemInterface::class);
        $cacheItem->expects($this->atLeastOnce())
            ->method('expiresAfter')
            ->with($this->isInstanceOf(\DateInterval::class));

        $this->security->expects($this->once())->method('isGranted')->with(Roles::ROLE_ADMIN)->willReturn(false);
        $this->security->expects($this->once())->method('getUser')->willReturn($user);
        $this->domainRepository->expects($this->never())->method('findBy');
        $this->hostIpResolver->expects($this->once())->method('resolveExpectedHostIps')->willReturn($expectedIps);
        $this->validator
            ->expects($this->atLeastOnce())
            ->method('validate')
            ->with('mail.example.com', $expectedIps, [$domain])
            ->willReturn($result);

        $this->cacheApp
            ->expects($this->once())
            ->method('get')
            ->with(
                $this->isString(),
                $this->callback(function (callable $callback) use ($cacheItem, $result) {
                    return $result === $callback($cacheItem);
                })
            )
            ->willReturnCallback(function (string $key, callable $callback) use ($cacheItem) {
                return $callback($cacheItem);
            });

        $this->twig->expects($this->once())->method('render')->willReturn('html');

        $controller = new DnsWizardAction(
            twig: $this->twig,
            domainRepository: $this->domainRepository,
            hostIpResolver: $this->hostIpResolver,
            validator: $this->validator,
            security: $this->security,
            cacheApp: $this->cacheApp,
            mailname: 'mail.example.com',
        );

        $response = $controller(new Request());

        self::assertSame('html', $response->getContent());
    }
}
