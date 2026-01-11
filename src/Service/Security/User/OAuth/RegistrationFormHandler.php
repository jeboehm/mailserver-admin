<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Security\User\OAuth;

use App\Exception\DomainNotFoundException;
use App\Factory\UserFactory;
use HWI\Bundle\OAuthBundle\Form\RegistrationFormHandlerInterface;
use HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

readonly class RegistrationFormHandler implements RegistrationFormHandlerInterface
{
    public function __construct(
        private UserFactory $userFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function process(Request $request, FormInterface $form, UserResponseInterface $userInformation): bool
    {
        $emailAddress = method_exists($userInformation, 'getUserIdentifier')
            ? $userInformation->getUserIdentifier() : $userInformation->getUsername();

        if (!filter_var($emailAddress, \FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('No email address found in OAuth response. Check your OAUTH_PATHS_IDENTIFIER setting.');
        }

        try {
            $user = $this->userFactory->createFromEmailAddress($emailAddress);
        } catch (DomainNotFoundException) {
            $this->logger->error(
                'No domain found for email address from OAuth provider: {emailAddress}',
                ['emailAddress' => $emailAddress]
            );

            return false;
        }

        $form->setData($user);
        $form->handleRequest($request);

        return $form->isSubmitted() && $form->isValid();
    }
}
