<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use App\Kernel;

require dirname(__DIR__) . '/bootstrap.php';

// Parsing DQL needs the platform, and without a version in the URL DBAL connects to the server
// to ask for it. Naming one here keeps the analysis independent of a running database.
$_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'mysql://phpstan@127.0.0.1/phpstan?serverVersion=8.4.0';

$kernel = new Kernel('test', true);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
