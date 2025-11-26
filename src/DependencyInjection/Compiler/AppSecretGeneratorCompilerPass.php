<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

readonly class AppSecretGeneratorCompilerPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        $cacheDir = $container->getParameter('kernel.cache_dir');
        $secretPath = sprintf('%s/app.secret', $cacheDir);
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
        $secret = substr(bin2hex(random_bytes(32)), 0, 12);

        if (!@file_put_contents($secretPath, $secret)) {
            throw new \RuntimeException(sprintf('Cannot write APP_SECRET file: %s', $secretPath));
        }

        return $secret;
    }
}
