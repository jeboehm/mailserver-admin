<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Mcp;

use App\Entity\ApiToken;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The API token that authenticated the current request.
 *
 * Authorisation must read from here and never from the MCP session: sessions are not bound to tokens.
 */
final readonly class CurrentApiToken
{
    public const string TOKEN_ATTRIBUTE = 'api_token';

    public function __construct(private Security $security)
    {
    }

    public function get(): ApiToken
    {
        $token = $this->security->getToken();

        if (null === $token || !$token->hasAttribute(self::TOKEN_ATTRIBUTE)) {
            throw new \LogicException('The current request was not authenticated with an API token.');
        }

        $apiToken = $token->getAttribute(self::TOKEN_ATTRIBUTE);

        if (!$apiToken instanceof ApiToken) {
            throw new \LogicException('The current request was not authenticated with an API token.');
        }

        return $apiToken;
    }
}
