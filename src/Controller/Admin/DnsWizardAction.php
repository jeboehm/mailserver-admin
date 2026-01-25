<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller\Admin;

use App\Entity\Domain;
use App\Entity\User;
use App\Repository\DomainRepository;
use App\Service\DnsWizard\DnsWizardValidator;
use App\Service\DnsWizard\ExpectedHostIps;
use App\Service\DnsWizard\HostIpResolver;
use App\Service\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Twig\Environment;

#[AdminRoute('/dns-wizard', name: 'dns_wizard')]
#[IsGranted(Roles::ROLE_DOMAIN_ADMIN)]
final readonly class DnsWizardAction
{
    public function __construct(
        private Environment $twig,
        private DomainRepository $domainRepository,
        private HostIpResolver $hostIpResolver,
        private DnsWizardValidator $validator,
        private Security $security,
        private CacheInterface $cacheApp,
        #[Autowire('%env(default::string:MAILNAME)%')]
        private ?string $mailname,
    ) {
    }

    #[AdminRoute('/', name: 'index')]
    public function __invoke(Request $request): Response
    {
        $mailname = $this->mailname ?? $request->getHost();

        $domains = $this->getVisibleDomains();
        $expectedIps = $this->hostIpResolver->resolveExpectedHostIps();
        $cacheKey = $this->getCacheKey($mailname, $expectedIps, $domains);

        $result = $this->cacheApp->get(
            $cacheKey,
            function (ItemInterface $item) use ($mailname, $expectedIps, $domains): array {
                $item->expiresAfter(new \DateInterval('PT1M'));

                return $this->validator->validate($mailname, $expectedIps, $domains);
            }
        );

        return new Response($this->twig->render('admin/dns_wizard/index.html.twig', [
            'mailname' => $mailname,
            'expectedIps' => $expectedIps,
            'result' => $result,
        ]));
    }

    /**
     * @return list<Domain>
     */
    private function getVisibleDomains(): array
    {
        if ($this->security->isGranted(Roles::ROLE_ADMIN)) {
            return $this->domainRepository->findBy([], ['name' => 'ASC']);
        }

        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new \LogicException('User is not an instance of User');
        }

        $domain = $user->getDomain();

        if (null === $domain) {
            throw new \RuntimeException('Domain admin user has no domain');
        }

        return [$domain];
    }

    /**
     * @param Domain[] $domains
     */
    private function getCacheKey(string $mailname, ExpectedHostIps $expectedIps, array $domains): string
    {
        $domains = array_map(static fn (Domain $domain) => $domain->getName(), $domains);
        $ips = $expectedIps->all();

        asort($domains);
        asort($ips);

        return md5($mailname . implode(',', $domains) . implode(',', $ips));
    }
}
