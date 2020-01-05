<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Command;

use App\Entity\Domain;
use App\Service\DKIM\FormatterService;
use App\Service\DKIM\KeyGenerationService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class DKIMSetupCommand extends Command
{
    private ManagerRegistry $manager;
    private KeyGenerationService $keyGenerationService;
    private FormatterService $formatterService;

    public function __construct(
        ?string $name = null,
        ManagerRegistry $manager,
        KeyGenerationService $keyGenerationService,
        FormatterService $formatterService
    ) {
        parent::__construct($name);

        $this->manager = $manager;
        $this->keyGenerationService = $keyGenerationService;
        $this->formatterService = $formatterService;
    }

    protected function configure(): void
    {
        $this
            ->setName('dkim:setup')
            ->addArgument('domain')
            ->addOption('enable', null, InputOption::VALUE_NONE, 'Enable DKIM signing for outgoing mails.')
            ->addOption('regenerate', null, InputOption::VALUE_NONE, 'Regenerate private key.')
            ->addOption('selector', null, InputOption::VALUE_REQUIRED, 'Set DKIM selector.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $domain = $this->getDomain($input, $output);

        if (null === $domain) {
            return 1;
        }

        $regenerateKey = (bool) $input->getOption('regenerate');

        if ($regenerateKey && !$this->warnOnKeyRegeneration($input, $output)) {
            return 1;
        }

        if (empty($domain->getDkimPrivateKey())) {
            $regenerateKey = true;
        }

        $selector = $input->getOption('selector') ?: \date('Y');

        if ($regenerateKey) {
            $keyPair = $this->keyGenerationService->createKeyPair();
            $domain->setDkimPrivateKey($keyPair->getPrivate());
        }

        $domain->setDkimSelector($selector);
        $domain->setDkimEnabled((bool) $input->getOption('enable'));

        $expectedDnsRecord = $this->formatterService->getTXTRecord(
            $this->keyGenerationService->extractPublicKey($domain->getDkimPrivateKey()),
            KeyGenerationService::DIGEST_ALGORITHM
        );

        $this->manager->getManager()->flush();

        $output->writeln(sprintf('<info>Add the following TXT record to %s.%s:</info>', $selector, $domain->getName()));
        $output->writeln('');
        $output->writeln($expectedDnsRecord);
        $output->writeln('');

        if ($domain->getDkimEnabled()) {
            $output->writeln('<info>DKIM is enabled.</info>');
        }

        return 0;
    }

    private function getDomain(InputInterface $input, OutputInterface $output): ?Domain
    {
        $name = $input->getArgument('domain');
        /** @var Domain $domain */
        $domain = $this->manager->getRepository(Domain::class)->findOneBy(['name' => $name]);

        if (!$domain) {
            $output->writeln(sprintf('<error>Domain "%s" was not found.</error>', $name));

            return null;
        }

        return $domain;
    }

    private function warnOnKeyRegeneration(InputInterface $input, OutputInterface $output): bool
    {
        /** @var QuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');
        $result = $questionHelper->ask(
            $input,
            $output,
            new ConfirmationQuestion(
                "<question>If you regenerate your private key, you'll have to update your DNS settings. Continue?</question>"
            )
        );

        if (!$result) {
            $output->writeln('Aborting.');

            return false;
        }

        return true;
    }
}
