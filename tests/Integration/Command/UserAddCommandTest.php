<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Command;

use App\Command\UserAddCommand;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class UserAddCommandTest extends WebTestCase
{
    public function testSomething(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $command = $container->get(UserAddCommand::class);
        $commandTester = new CommandTester($command);

        $commandTester->execute(
            ['name' => 'jeff', 'domain' => 'example.com', '--password' => 'jeff1234']
        );

        self::assertEquals(Command::SUCCESS, $commandTester->getStatusCode());

        $repository = $container->get(UserRepository::class);
        $user = $repository->findOneByEmailAddress('jeff@example.com');
        self::assertNotNull($user);
        self::assertNotEmpty($user->getPassword());
        self::assertStringStartsWith('$2y$13', $user->getPassword());
    }
}
