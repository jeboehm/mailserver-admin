<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\Domain;
use App\Service\DKIM\KeyGenerationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/dkim")
 */
class DKIMController extends AbstractController
{
    private KeyGenerationService $keyGenerationService;

    public function __construct(KeyGenerationService $keyGenerationService)
    {
        $this->keyGenerationService = $keyGenerationService;
    }

    /**
     * @Route("/recreate", name="app_dkim_recreate")
     */
    public function recreateAction(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $em = $this->getDoctrine()->getManager();
        $repository = $this->getDoctrine()->getRepository(Domain::class);

        $id = $request->query->get('id');
        $domain = $repository->find($id);

        if (null === $domain) {
            $this->createNotFoundException(sprintf('Domain %d not found', $id));
        }

        $keyPair = $this->keyGenerationService->createKeyPair();
        $domain->setDkimPrivateKey($keyPair->getPrivate());

        $em->flush();

        $this->addFlash('info', 'Private key successfully recreated. You need to update your DNS zone now.');

        return $this->redirectToRoute('easyadmin', [
            'action' => 'edit',
            'id' => $id,
            'entity' => $request->query->get('entity'),
        ]);
    }
}
