<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\McpSetup;

final readonly class McpClientGuide
{
    public function __construct(
        public string $name,
        public string $configFile,
        public string $snippet,
        public string $docsUrl,
        public string $tokenHint,
    ) {
    }
}
