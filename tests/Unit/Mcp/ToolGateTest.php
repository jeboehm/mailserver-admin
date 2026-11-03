<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Mcp;

use App\Entity\ApiToken;
use App\Entity\Domain;
use App\Entity\User;
use App\Exception\Mailbox\MailboxDeniedException;
use App\Mcp\Audit\ToolAuditLog;
use App\Mcp\Audit\ToolCallResult;
use App\Mcp\CurrentApiToken;
use App\Mcp\ToolAccess;
use App\Mcp\ToolAccessPolicy;
use App\Mcp\ToolDeniedException;
use App\Mcp\ToolGate;
use App\Service\ApiToken\ApiTokenScope;
use App\Service\Security\Access\ActorRole;
use App\Service\Security\Access\CurrentActor;
use App\Service\Security\Roles;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Tool;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final class GateFixtureTools
{
    #[ToolAccess(scope: ApiTokenScope::ManageRead, minimumRole: ActorRole::DomainAdmin, target: 'user:{id}')]
    public function managed(): void
    {
    }

    #[ToolAccess(target: 'message:{mailbox}/{uid}')]
    public function openWithTarget(): void
    {
    }

    #[ToolAccess(scope: ApiTokenScope::MailSend)]
    public function send(): void
    {
    }

    #[ToolAccess]
    public function open(): void
    {
    }

    public function undeclared(): void
    {
    }
}

class ToolGateTest extends TestCase
{
    private TestHandler $auditRecords;

    /**
     * @return iterable<string, array{string, ActorRole, list<ApiTokenScope>, bool}>
     */
    public static function accessMatrix(): iterable
    {
        yield 'role and scope fit' => ['managed', ActorRole::DomainAdmin, [ApiTokenScope::ManageRead], true];
        yield 'higher role fits' => ['managed', ActorRole::Admin, [ApiTokenScope::ManageRead], true];
        yield 'role too low' => ['managed', ActorRole::User, [ApiTokenScope::ManageRead], false];
        yield 'scope missing, even for an admin' => ['managed', ActorRole::Admin, [ApiTokenScope::ManageWrite], false];
        yield 'scope fits, lowest role allowed' => ['send', ActorRole::User, [ApiTokenScope::MailSend], true];
        yield 'write does not imply send' => ['send', ActorRole::Admin, [ApiTokenScope::ManageWrite, ApiTokenScope::MailRead], false];
        yield 'no scope needed' => ['open', ActorRole::User, [], true];
        yield 'undeclared tool is refused' => ['undeclared', ActorRole::Admin, ApiTokenScope::ALL, false];
    }

    /**
     * @param list<ApiTokenScope> $scopes
     */
    #[DataProvider('accessMatrix')]
    public function testToolRunsOnlyIfRoleAndScopeAllowIt(string $method, ActorRole $role, array $scopes, bool $allowed): void
    {
        $inner = $this->createMock(ReferenceHandlerInterface::class);
        $inner->expects($allowed ? $this->once() : $this->never())->method('handle')->willReturn('result');

        $gate = $this->gate($inner, $role, $scopes);

        if (!$allowed) {
            $this->expectException(ToolCallException::class);
            $this->expectExceptionMessage(ToolDeniedException::PUBLIC_MESSAGE);
        }

        self::assertSame('result', $gate->handle($this->reference($method), []));
    }

    public function testListingAndCallingUseTheSameRule(): void
    {
        $policy = $this->policy(ActorRole::User, [ApiTokenScope::ManageRead]);

        self::assertFalse($policy->allows($this->reference('managed')));
        self::assertTrue($policy->allows($this->reference('open')));
    }

    public function testDeniedAndNotFoundLookTheSame(): void
    {
        $deniedByGate = $this->messageOf(fn () => $this->gate($this->createStub(ReferenceHandlerInterface::class), ActorRole::User, [])->handle($this->reference('managed'), []));

        $inner = $this->createStub(ReferenceHandlerInterface::class);
        $inner->method('handle')->willThrowException(new AccessDeniedException('This fetchmail account cannot be deleted.'));
        $deniedByPolicy = $this->messageOf(fn () => $this->gate($inner, ActorRole::User, [])->handle($this->reference('open'), []));

        $inner = $this->createStub(ReferenceHandlerInterface::class);
        $inner->method('handle')->willThrowException(new ToolDeniedException());
        $notFound = $this->messageOf(fn () => $this->gate($inner, ActorRole::User, [])->handle($this->reference('open'), []));

        $inner = $this->createStub(ReferenceHandlerInterface::class);
        $inner->method('handle')->willThrowException(new MailboxDeniedException());
        $noMailbox = $this->messageOf(fn () => $this->gate($inner, ActorRole::User, [])->handle($this->reference('open'), []));

        self::assertSame($notFound, $deniedByGate);
        self::assertSame($notFound, $deniedByPolicy);
        self::assertSame($notFound, $noMailbox);
    }

    public function testSuccessIsAudited(): void
    {
        $inner = $this->createStub(ReferenceHandlerInterface::class);
        $inner->method('handle')->willReturn('result');

        $this->gate($inner, ActorRole::User, [ApiTokenScope::MailSend], $this->request('203.0.113.9'))->handle($this->reference('send'), ['password' => 'sentinel-password']);

        $record = $this->singleAuditRecord();
        self::assertSame('send', $record['tool']);
        self::assertSame('ok', $record['result']);
        self::assertSame(42, $record['token_id']);
        self::assertSame(7, $record['user_id']);
        self::assertSame('someone@example.com', $record['user']);
        self::assertSame('203.0.113.9', $record['client_ip']);
        self::assertStringNotContainsString('sentinel-password', (string) json_encode($record));
    }

    public function testTargetAndDetailsSetByTheToolAreAudited(): void
    {
        $auditLog = $this->auditLog(null);
        $inner = $this->createStub(ReferenceHandlerInterface::class);
        $inner->method('handle')->willReturnCallback(static function () use ($auditLog): string {
            $auditLog->target('user', 12);
            $auditLog->details(['recipients' => ['a@example.org']]);

            return 'result';
        });

        $this->gate($inner, ActorRole::User, [], auditLog: $auditLog)->handle($this->reference('open'), []);

        $record = $this->singleAuditRecord();
        self::assertSame('user:12', $record['target']);
        self::assertSame(['a@example.org'], $record['recipients']);
    }

    public function testTargetDoesNotLeakIntoTheNextRecord(): void
    {
        $auditLog = $this->auditLog(null);
        $auditLog->target('user', 12);
        $auditLog->record('first', ToolCallResult::Ok, $this->storedToken([]));
        $auditLog->record('second', ToolCallResult::Ok, $this->storedToken([]));

        self::assertNull($this->auditRecords->getRecords()[1]->context['target']);
    }

    public function testDenialIsAudited(): void
    {
        try {
            $this->gate($this->createStub(ReferenceHandlerInterface::class), ActorRole::User, [])->handle($this->reference('managed'), []);
            self::fail('Expected a denial.');
        } catch (ToolCallException) {
        }

        self::assertSame('denied', $this->singleAuditRecord()['result']);
    }

    public function testDenialByTheGateRecordsTheTargetButNoOtherArgument(): void
    {
        $this->messageOf(fn () => $this->gate($this->createStub(ReferenceHandlerInterface::class), ActorRole::User, [])->handle(
            $this->reference('managed'),
            ['id' => 12, 'password' => 'sentinel-password', 'body' => 'sentinel-body'],
        ));

        $record = $this->singleAuditRecord();
        self::assertSame('denied', $record['result']);
        self::assertSame('user:12', $record['target']);
        self::assertStringNotContainsString('sentinel', (string) json_encode($record));
    }

    public function testDenialInsideTheToolRecordsTheTargetFromTheArguments(): void
    {
        $inner = $this->createStub(ReferenceHandlerInterface::class);
        $inner->method('handle')->willThrowException(new ToolDeniedException());

        $this->messageOf(fn () => $this->gate($inner, ActorRole::User, [])->handle($this->reference('openWithTarget'), ['mailbox' => 'INBOX', 'uid' => 3]));

        self::assertSame('message:INBOX/3', $this->singleAuditRecord()['target']);
    }

    public function testTheToolCanStateTheTargetMorePrecisely(): void
    {
        $auditLog = $this->auditLog(null);
        $inner = $this->createStub(ReferenceHandlerInterface::class);
        $inner->method('handle')->willReturnCallback(static function () use ($auditLog): string {
            $auditLog->target('message', 'canonical');

            return 'result';
        });

        $this->gate($inner, ActorRole::User, [], auditLog: $auditLog)->handle($this->reference('openWithTarget'), ['mailbox' => 'INBOX', 'uid' => 3]);

        self::assertSame('message:canonical', $this->singleAuditRecord()['target']);
    }

    /**
     * Denied calls are refused before the SDK checks the arguments, so these can be anything.
     *
     * @return iterable<string, array{array<string, mixed>, string|null}>
     */
    public static function unusualTargetArguments(): iterable
    {
        yield 'none given' => [[], null];
        yield 'not a scalar' => [['mailbox' => ['a' => 'b'], 'uid' => null], null];
        yield 'a boolean' => [['mailbox' => true], null];
        yield 'partly given' => [['mailbox' => 'INBOX'], 'message:INBOX/?'];
        yield 'control characters cannot forge a log line' => [['mailbox' => "a\r\nb\x00c", 'uid' => 1], 'message:abc/1'];
        yield 'length is bounded' => [['mailbox' => str_repeat('x', 1000), 'uid' => 1], 'message:' . str_repeat('x', 247)];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('unusualTargetArguments')]
    public function testUnusualArgumentsAreRecordedSafely(array $arguments, ?string $target): void
    {
        $inner = $this->createStub(ReferenceHandlerInterface::class);
        $inner->method('handle')->willThrowException(new ToolDeniedException());

        $this->messageOf(fn () => $this->gate($inner, ActorRole::User, [])->handle($this->reference('openWithTarget'), $arguments));

        self::assertSame($target, $this->singleAuditRecord()['target']);
    }

    public function testToolFailureIsAuditedAndKeepsItsMessage(): void
    {
        $inner = $this->createStub(ReferenceHandlerInterface::class);
        $inner->method('handle')->willThrowException(new ToolCallException('Mailbox busy, try again.'));

        self::assertSame(
            'Mailbox busy, try again.',
            $this->messageOf(fn () => $this->gate($inner, ActorRole::User, [])->handle($this->reference('open'), [])),
        );
        self::assertSame('failed', $this->singleAuditRecord()['result']);
    }

    public function testUnexpectedErrorIsAuditedAndRethrown(): void
    {
        $inner = $this->createStub(ReferenceHandlerInterface::class);
        $inner->method('handle')->willThrowException(new \RuntimeException('boom'));

        try {
            $this->gate($inner, ActorRole::User, [])->handle($this->reference('open'), []);
            self::fail('Expected the error to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame('error', $this->singleAuditRecord()['result']);
    }

    public function testNonToolElementsPassThrough(): void
    {
        $inner = $this->createMock(ReferenceHandlerInterface::class);
        $inner->expects($this->once())->method('handle')->willReturn('prompt');

        self::assertSame('prompt', $this->gate($inner, ActorRole::User, [])->handle(new ElementReference('some_function'), []));
    }

    /**
     * @param list<ApiTokenScope> $scopes
     */
    private function gate(ReferenceHandlerInterface $inner, ActorRole $role, array $scopes, ?Request $request = null, ?ToolAuditLog $auditLog = null): ToolGate
    {
        $security = $this->security($role, $scopes);
        $auditLog ??= $this->auditLog($request);

        return new ToolGate($inner, $this->policyFor($security), new CurrentApiToken($security), $auditLog);
    }

    /**
     * @param list<ApiTokenScope> $scopes
     */
    private function policy(ActorRole $role, array $scopes): ToolAccessPolicy
    {
        return $this->policyFor($this->security($role, $scopes));
    }

    private function policyFor(Security $security): ToolAccessPolicy
    {
        return new ToolAccessPolicy(new CurrentApiToken($security), new CurrentActor($security), mailReadEnabled: false, mailSendEnabled: false);
    }

    private function auditLog(?Request $request): ToolAuditLog
    {
        $this->auditRecords = new TestHandler();
        $requestStack = new RequestStack();

        if (null !== $request) {
            $requestStack->push($request);
        }

        return new ToolAuditLog(new Logger('mcp_audit', [$this->auditRecords]), $requestStack);
    }

    /**
     * @param list<ApiTokenScope> $scopes
     */
    private function security(ActorRole $role, array $scopes): Security
    {
        $token = $this->storedToken($scopes);
        $securityToken = new PostAuthenticationToken($token->getOwner(), 'mcp', $token->getOwner()->getRoles());
        $securityToken->setAttribute(CurrentApiToken::TOKEN_ATTRIBUTE, $token);

        $granted = match ($role) {
            ActorRole::Admin => [Roles::ROLE_ADMIN, Roles::ROLE_DOMAIN_ADMIN, Roles::ROLE_USER],
            ActorRole::DomainAdmin => [Roles::ROLE_DOMAIN_ADMIN, Roles::ROLE_USER],
            ActorRole::User => [Roles::ROLE_USER],
        };

        $security = $this->createStub(Security::class);
        $security->method('getToken')->willReturn($securityToken);
        $security->method('getUser')->willReturn($token->getOwner());
        $security->method('isGranted')->willReturnCallback(static fn (string $attribute): bool => \in_array($attribute, $granted, true));

        return $security;
    }

    /**
     * @param list<ApiTokenScope> $scopes
     */
    private function storedToken(array $scopes): ApiToken
    {
        $domain = new Domain();
        $domain->setName('example.com');

        $owner = new User();
        $owner->setName('someone');
        $owner->setDomain($domain);
        new \ReflectionProperty(User::class, 'id')->setValue($owner, 7);

        $token = new ApiToken($owner, 'test', ApiToken::hashSecret('secret'), $scopes, new \DateTimeImmutable('+1 day'));
        new \ReflectionProperty(ApiToken::class, 'id')->setValue($token, 42);

        return $token;
    }

    private function request(string $clientIp): Request
    {
        return Request::create('/mcp', server: ['REMOTE_ADDR' => $clientIp]);
    }

    private function reference(string $method): ToolReference
    {
        return new ToolReference(new Tool($method, null, ['type' => 'object', 'properties' => new \stdClass()], null, null), [GateFixtureTools::class, $method]);
    }

    private function messageOf(callable $call): string
    {
        try {
            $call();
        } catch (ToolCallException $e) {
            return $e->getMessage();
        }

        self::fail('Expected a ToolCallException.');
    }

    /**
     * @return array<string, mixed>
     */
    private function singleAuditRecord(): array
    {
        $records = $this->auditRecords->getRecords();
        self::assertCount(1, $records);

        return $records[0]->context;
    }
}
