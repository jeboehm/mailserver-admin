<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Mcp;

use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Writes for tools: through the Symfony validator and Doctrine, so the rules of the forms and the
 * entity listeners apply.
 */
final readonly class ToolEntityWriter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * Only names the field and the constraint's message, never the rejected value: it can be a password.
     *
     * @param list<string>          $groups
     * @param array<string, string> $fieldNames entity property => name of the tool argument, where they differ
     *
     * @throws ToolCallException
     */
    public function validate(object $entity, array $groups = ['Default'], array $fieldNames = []): void
    {
        $problems = [];

        foreach ($this->validator->validate($entity, groups: $groups) as $violation) {
            $path = $violation->getPropertyPath();
            $problems[] = \sprintf('%s: %s', $fieldNames[$path] ?? $path, $violation->getMessage());
        }

        if ([] !== $problems) {
            throw new ToolCallException(\sprintf('Validation failed. %s', implode(' ', $problems)));
        }
    }

    public function save(object $entity): void
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function remove(object $entity): void
    {
        $this->entityManager->remove($entity);
        $this->entityManager->flush();
    }
}
