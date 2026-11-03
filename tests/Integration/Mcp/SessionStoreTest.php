<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Mcp;

use Predis\ClientInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler;

/**
 * The test environment uses mock session storage, so this pins the production handler itself.
 * Two replicas cannot be shown here. That was checked by hand on a real FrankenPHP stack: a session
 * created on replica A worked on replica B and back.
 */
class SessionStoreTest extends KernelTestCase
{
    /**
     * Sessions written by php.ini's phpredis handler before the upgrade live under this prefix.
     * Keeping it is what keeps people logged in, so changing it logs everybody out once.
     */
    public function testWebSessionsAreStoredWhereThePhpIniHandlerKeptThem(): void
    {
        self::bootKernel();
        $handler = static::getContainer()->get(RedisSessionHandler::class);
        $redis = static::getContainer()->get(ClientInterface::class);
        $sessionId = 'sessionstoretest' . bin2hex(random_bytes(8));
        $key = 'PHPREDIS_SESSION:' . $sessionId;

        $handler->open('', 'PHPSESSID');
        $handler->write($sessionId, 'a|i:1;');

        self::assertSame('a|i:1;', $redis->get($key));
        self::assertGreaterThan(0, $redis->ttl($key));

        $redis->set($key, 'written|s:6:"before";');
        self::assertSame('written|s:6:"before";', $handler->read($sessionId));

        $handler->destroy($sessionId);
        self::assertSame(0, $redis->exists($key));
    }
}
