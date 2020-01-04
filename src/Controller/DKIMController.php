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
use App\Form\DKIM\DKIMDefaultType;
use App\Form\DKIM\DKIMKeyGenerationType;
use App\Repository\DomainRepository;
use App\Service\DKIM\DKIMStatusService;
use App\Service\DKIM\FormatterService;
use App\Service\DKIM\KeyGenerationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/DKIM")
 */
class DKIMController extends AbstractController
{
    private DKIMStatusService $dkimStatusService;

    private KeyGenerationService $keyGenerationService;

    private FormatterService $formatterService;

    public function __construct(
        DKIMStatusService $dkimStatusService,
        KeyGenerationService $keyGenerationService,
        FormatterService $formatterService
    ) {
        $this->dkimStatusService = $dkimStatusService;
        $this->keyGenerationService = $keyGenerationService;
        $this->formatterService = $formatterService;
    }

    /**
     * @Route("/", name="app_dkim_index")
     */
    public function indexAction(DomainRepository $domainRepository): Response
    {
        $domains = $domainRepository->findBy([], ['name' => 'asc']);
        $output = [];

        foreach ($domains as $domain) {
            $output[] = $this->prepareDomainView($domain);
        }

        return $this->render('dkim/index.html.twig', ['domains' => $output]);
    }

    /**
     * @Route("/edit/{domain}", name="app_dkim_edit")
     */
    public function editAction(Request $request, Domain $domain): Response
    {
        $formType = $this->getFormTypeForDomain($domain);
        $form = $this->createForm($formType, $domain);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->has('create_private_key') && $form->get('create_private_key')->isClicked()) {
                $keyPair = $this->keyGenerationService->createKeyPair();
                $domain->setDkimPrivateKey($keyPair->getPrivate());
            }

            $this->getDoctrine()->getManager()->flush();

            return $this->redirectToRoute('app_dkim_edit', ['domain' => $domain->getId()]);
        }

        if (!empty($domain->getDkimPrivateKey())) {
            $expectedDnsRecord = $this->formatterService->getTXTRecord(
                $this->keyGenerationService->extractPublicKey($domain->getDkimPrivateKey()),
                KeyGenerationService::DIGEST_ALGORITHM
            );
        }

        return $this->render('dkim/edit.html.twig', [
            'domain' => $this->prepareDomainView($domain),
            'form' => $form->createView(),
            'expectedDnsRecord' => $expectedDnsRecord ?? null,
        ]);
    }

    private function prepareDomainView(Domain $domain): array
    {
        return [
            'entity' => $domain,
            'status' => $this->dkimStatusService->getStatus($domain),
        ];
    }

    private function getFormTypeForDomain(Domain $domain): string
    {
        $type = DKIMDefaultType::class;

        if (empty($domain->getDkimSelector()) || empty($domain->getDkimPrivateKey())) {
            $type = DKIMKeyGenerationType::class;
        }

        return $type;
    }
}
