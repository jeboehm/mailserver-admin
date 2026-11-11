<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Mcp\Tool;

use App\Exception\MailSend\InvalidMailException;
use App\Exception\MailSend\MailSendFailedException;
use App\Exception\MailSend\MailSendRateLimitedException;
use App\Exception\MailSend\SenderNotAllowedException;
use App\Mcp\Audit\ToolAuditLog;
use App\Mcp\CurrentApiToken;
use App\Mcp\ToolAccess;
use App\Mcp\ToolFeature;
use App\Mcp\ToolTimeLimit;
use App\Service\ApiToken\ApiTokenScope;
use App\Service\MailSend\MailComposer;
use App\Service\MailSend\MailSender;
use App\Service\MailSend\SenderResolver;
use App\Service\MailSend\SentCopy;
use App\Service\MailSend\SentCopyStatus;
use App\Service\Security\Access\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Send mail from the authenticated user's own addresses, and only those: the sender mode of the token
 * decides which one, see SenderResolver. Roles grant nothing here.
 */
final readonly class MailSendTools
{
    /**
     * Above PHP's default of 30: submission and the Sent copy can each wait for an auth penalty of up
     * to 15 seconds. Running out after the mail has gone would answer with an error, and a caller
     * would send it again.
     */
    private const int TIME_LIMIT_SECONDS = 120;

    private const string COPY_FAILED = 'The mail was sent, but its copy could not be saved in the Sent mailbox. Do not send it again.';
    private const string COPY_SKIPPED = 'This user has no access to a mailbox, so no copy is kept.';

    public function __construct(
        private MailComposer $composer,
        private SenderResolver $senderResolver,
        private MailSender $sender,
        private CurrentActor $currentActor,
        private CurrentApiToken $currentApiToken,
        private ToolAuditLog $auditLog,
    ) {
    }

    /**
     * @param string[]    $to           the recipients, as plain addresses such as name@example.com
     * @param string      $subject      the subject
     * @param string      $body         the body, as plain text
     * @param string[]    $cc           recipients everyone can see
     * @param string[]    $bcc          recipients nobody else can see
     * @param string|null $from_address the sender, only for a token that lets you choose it; leave it out otherwise
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'message_send',
        description: 'Sends a plain text mail from your own address, as "send_as" in whoami shows, and keeps a copy in your Sent mailbox. A mail cannot be recalled. If "sent" is true in the result it has gone out, whatever the result says about the copy, so do not send it again. Limited per token, per user and overall.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true),
    )]
    #[ToolAccess(scope: ApiTokenScope::MailSend, feature: ToolFeature::MailSend)]
    public function messageSend(array $to, string $subject, string $body, array $cc = [], array $bcc = [], ?string $from_address = null): array
    {
        ToolTimeLimit::raiseTo(self::TIME_LIMIT_SECONDS);

        $user = $this->currentActor->get()->user;
        $token = $this->currentApiToken->get();

        try {
            $from = $this->senderResolver->resolve($token, $user, $from_address);
            $this->auditLog->details(['from' => $from]);

            $mail = $this->composer->compose($from, array_values($to), array_values($cc), array_values($bcc), $subject, $body);

            $this->auditLog->target('message', $mail->messageId);
            $this->auditLog->details(['recipients' => $mail->recipients, 'message_id' => $mail->messageId]);

            $copy = $this->sender->send($user, (int) $token->getId(), $mail);
        } catch (SenderNotAllowedException|InvalidMailException|MailSendRateLimitedException|MailSendFailedException $e) {
            throw new ToolCallException($e->getMessage());
        }

        $this->auditLog->details(['sent_copy' => $copy->status->value]);

        return [
            'sent' => true,
            'message_id' => $mail->messageId,
            'from' => $from,
            'recipients' => $mail->recipients,
            'sent_copy' => $this->describe($copy),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(SentCopy $copy): array
    {
        return ['status' => $copy->status->value, ...match ($copy->status) {
            SentCopyStatus::Saved => ['mailbox' => $copy->mailbox],
            SentCopyStatus::Skipped => ['reason' => self::COPY_SKIPPED],
            SentCopyStatus::Failed => ['temporary' => $copy->temporary, 'reason' => self::COPY_FAILED],
        }];
    }
}
