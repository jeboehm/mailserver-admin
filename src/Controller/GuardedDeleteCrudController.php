<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * A CRUD controller whose deletes all pass one access rule.
 *
 * EasyAdmin's batch delete does not apply the entity permission set in configureCrud(),
 * so that permission cannot protect it. It also flushes per entry, which is why all
 * entries are checked up front: a rejected batch removes nothing.
 *
 * @extends AbstractCrudController<object>
 */
abstract class GuardedDeleteCrudController extends AbstractCrudController
{
    #[\Override]
    final public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $this->denyUnlessCanDelete($entityInstance);

        parent::deleteEntity($entityManager, $entityInstance);
    }

    #[\Override]
    public function batchDelete(AdminContext $context, BatchActionDto $batchActionDto): Response
    {
        // The routed CRUD decides the entity class. The class in the request body is untrusted.
        $entityFqcn = $context->getEntity()->getFqcn();
        $manager = $this->container->get('doctrine')->getManagerForClass($entityFqcn)
            ?? throw new \LogicException(\sprintf('No entity manager handles "%s".', $entityFqcn));
        $repository = $manager->getRepository($entityFqcn);

        foreach ($batchActionDto->getEntityIds() as $entityId) {
            $entity = $repository->find($entityId);

            if (null !== $entity) {
                $this->denyUnlessCanDelete($entity);
            }
        }

        return parent::batchDelete($context, $batchActionDto);
    }

    /**
     * @throws AccessDeniedException
     */
    abstract protected function denyUnlessCanDelete(object $entity): void;
}
