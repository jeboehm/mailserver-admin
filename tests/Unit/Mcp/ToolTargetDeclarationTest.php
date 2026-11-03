<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Mcp;

use App\Mcp\ToolAccess;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * What a tool declares as its audit target ends up in the log for every call, so a declaration must
 * not reach for anything the audit log has to stay free of.
 */
class ToolTargetDeclarationTest extends TestCase
{
    /** Mail content and recipients: the latter are logged for a sent mail only, not for any call. */
    private const array NEVER_A_TARGET = ['body', 'subject', 'text', 'from', 'to', 'cc', 'bcc'];

    /** A call with one of these names one object, which belongs in the record. */
    private const array NAMES_AN_OBJECT = ['id', 'domain', 'mailbox', 'uid'];

    /**
     * @return iterable<string, array{\ReflectionMethod}>
     */
    public static function tools(): iterable
    {
        foreach (new Finder()->files()->in(\dirname(__DIR__, 3) . '/src/Mcp/Tool')->name('*.php') as $file) {
            $class = new \ReflectionClass('App\Mcp\Tool\\' . $file->getBasename('.php'));

            foreach ($class->getMethods() as $method) {
                foreach ($method->getAttributes(McpTool::class) as $tool) {
                    yield $tool->getArguments()['name'] => [$method];
                }
            }
        }
    }

    #[DataProvider('tools')]
    public function testDeclaredTargetOnlyUsesHarmlessArguments(\ReflectionMethod $method): void
    {
        $target = $method->getAttributes(ToolAccess::class)[0]->newInstance()->target;

        preg_match_all('/\{(\w+)\}/', (string) $target, $placeholders);
        $this->addToAssertionCount(1);

        foreach ($placeholders[1] as $name) {
            $parameter = array_find($method->getParameters(), static fn (\ReflectionParameter $parameter): bool => $parameter->getName() === $name);

            self::assertNotNull($parameter, \sprintf('The target names "%s", which is no argument of this tool.', $name));
            self::assertSame([], $parameter->getAttributes(\SensitiveParameter::class), \sprintf('"%s" is sensitive.', $name));
            self::assertNotContains($name, self::NEVER_A_TARGET);
        }
    }

    #[DataProvider('tools')]
    public function testToolNamingAnObjectDeclaresItsTarget(\ReflectionMethod $method): void
    {
        $arguments = array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $method->getParameters());

        if ([] === array_intersect($arguments, self::NAMES_AN_OBJECT)) {
            $this->expectNotToPerformAssertions();

            return;
        }

        self::assertNotNull($method->getAttributes(ToolAccess::class)[0]->newInstance()->target);
    }
}
