<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Security;

use HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface;
use HWI\Bundle\OAuthBundle\Security\Core\User\OAuthAwareUserProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Exception\SessionUnavailableException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface as TUser;
use Symfony\Component\Security\Core\User\UserProviderInterface;

readonly class OAuthUserProvider implements UserProviderInterface, OAuthAwareUserProviderInterface
{
    private const string SESSION_KEY = 'oauth_user_provider';

    public function __construct(
        #[Autowire(param: 'app_oauth_enabled')]
        private bool $oauthEnabled,
        #[Autowire(param: 'app_oauth_admin_group')]
        private string $adminGroupName,
        private RequestStack $requestStack,
    ) {
    }

    public function refreshUser(TUser $user): TUser
    {
        $this->checkOAuthEnabled();

        assert($user instanceof OAuthStaticUser);

        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return OAuthStaticUser::class === $class;
    }

    public function loadUserByIdentifier(string $identifier): TUser
    {
        $this->checkOAuthEnabled();

        if ($this->getSession()->has(self::SESSION_KEY)) {
            $user = $this->getSession()->get(self::SESSION_KEY);

            if ($user instanceof OAuthStaticUser
                && $user->getUserIdentifier() === $identifier) {
                return $user;
            }
        }

        throw new UserNotFoundException();
    }

    public function loadUserByOAuthUserResponse(UserResponseInterface $response): OAuthStaticUser
    {
        $this->checkOAuthEnabled();

        $identifier = $response->getNickname() ?: $response->getUserIdentifier();
        $admin = false;

        if ('' === $this->adminGroupName) {
            $admin = true;
        } elseif (isset($response->getData()['groups'])) {
            $groups = array_map(strtolower(...), (array) $response->getData()['groups']);
            $admin = in_array($this->adminGroupName, $groups, true);
        }

        $user = new OAuthStaticUser($identifier, $admin);
        $this->getSession()->set(self::SESSION_KEY, $user);

        return $user;
    }

    private function getSession(): SessionInterface
    {
        return $this->requestStack->getCurrentRequest()?->getSession()
            ?? throw new SessionUnavailableException();
    }

    private function checkOAuthEnabled(): void
    {
        if (!$this->oauthEnabled) {
            throw new UserNotFoundException('OAuth not enabled');
        }
    }
}
