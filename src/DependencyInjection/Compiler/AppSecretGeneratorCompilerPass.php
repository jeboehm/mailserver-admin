<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\DependencyInjection\Compiler;

use RuntimeException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class AppSecretGeneratorCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $projectDir = $container->getParameter('kernel.project_dir');
        $secretPath = sprintf('%s/var/app.secret', $projectDir);
        $secret = null;

        if (is_readable($secretPath)) {
            $secret = file_get_contents($secretPath);

            if (!$secret || strlen($secret) < 8) {
                $secret = null;
            }
        }

        if (!$secret) {
            $secret = $this->createSecret($secretPath);
        }

        $container->setParameter('env(APP_SECRET)', $secret);
    }

    private function createSecret(string $secretPath): string
    {
        $secret = random_bytes(128);
        $secret = sha1($secret);
        $secret = substr($secret, 0, 9);

        if (!file_put_contents($secretPath, $secret)) {
            throw new RuntimeException(sprintf('Cannot write APP_SECRET file: %s', $secretPath));
        }

        return $secret;
    }
}
