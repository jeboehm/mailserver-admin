<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller\User;

use App\Entity\User;
use App\Service\MobileConfig\MobileConfigService;
use App\Service\Security\Roles;
use App\Service\Security\Voter\LocalUserVoter;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminRoute('/mobileconfig', name: 'mobileconfig')]
#[IsGranted(Roles::ROLE_USER)]
#[IsGranted(LocalUserVoter::KEY)]
final readonly class MobileConfigAction
{
    public function __construct(
        private MobileConfigService $mobileConfigService,
        private Security $security,
    ) {
    }

    #[AdminRoute('/', name: 'download')]
    #[IsGranted(LocalUserVoter::KEY)]
    public function __invoke(Request $request): Response
    {
        $user = $this->security->getUser();

        if (!($user instanceof User)) {
            throw new \LogicException('User must be a local user (not OAuth) to download mobileconfig.');
        }

        try {
            $signedProfile = $this->mobileConfigService->generateSignedProfile($user);
        } catch (\RuntimeException $e) {
            return new Response(
                content: sprintf('Error generating mobileconfig: %s', $e->getMessage()),
                status: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        $filename = sprintf('%s.mobileconfig', str_replace('@', '_at_', (string) $user));

        return new Response(
            content: $signedProfile,
            headers: [
                'Content-Type' => 'application/x-apple-aspen-config',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            ]
        );
    }
}
