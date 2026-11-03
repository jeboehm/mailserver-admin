<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Mcp\Security;

use App\Mcp\CurrentApiToken;
use App\Repository\ApiTokenRepository;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Turns a bearer token into its owner, loaded from the database on every request. The firewall's
 * user checker then refuses disabled owners.
 */
final readonly class ApiTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(private ApiTokenRepository $repository)
    {
    }

    #[\Override]
    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $token = $this->repository->findOneBySecret($accessToken);

        if (null === $token || !$token->isActive()) {
            throw new BadCredentialsException('Invalid credentials.');
        }

        $owner = $token->getOwner();

        return new UserBadge(
            $owner->getUserIdentifier(),
            static fn () => $owner,
            [CurrentApiToken::TOKEN_ATTRIBUTE => $token],
        );
    }
}
