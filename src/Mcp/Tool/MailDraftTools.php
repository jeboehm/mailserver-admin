<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Mcp\Tool;

use App\Exception\MailDraft\MailDraftFailedException;
use App\Exception\MailDraft\MailDraftRateLimitedException;
use App\Exception\MailSend\InvalidMailException;
use App\Mcp\Audit\ToolAuditLog;
use App\Mcp\CurrentApiToken;
use App\Mcp\ToolAccess;
use App\Mcp\ToolTimeLimit;
use App\Service\ApiToken\ApiTokenScope;
use App\Service\MailDraft\MailDrafter;
use App\Service\MailSend\MailComposer;
use App\Service\Security\Access\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Save mail as a draft for the authenticated user, and only that: nothing is sent, the sender always
 * comes from the token and no tool has a parameter for it or for a mailbox. A person sends the draft.
 */
final readonly class MailDraftTools
{
    /**
     * Above PHP's default of 30: the IMAP login can wait for an auth penalty of up to 15 seconds.
     */
    private const int TIME_LIMIT_SECONDS = 60;

    public function __construct(
        private MailComposer $composer,
        private MailDrafter $drafter,
        private CurrentActor $currentActor,
        private CurrentApiToken $currentApiToken,
        private ToolAuditLog $auditLog,
    ) {
    }

    /**
     * @param string[] $to      the recipients, as plain addresses such as name@example.com
     * @param string   $subject the subject
     * @param string   $body    the body, as plain text
     * @param string[] $cc      recipients everyone can see
     * @param string[] $bcc     recipients nobody else can see
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'message_draft',
        description: 'Saves a plain text mail from your own address as a draft in your Drafts mailbox. The mail is NOT sent: a person has to open the draft in their mail client, check it and send it themselves. Every call creates a new draft. If "drafted" is true in the result the draft exists, so do not call this again for the same mail. A failure is an error and no draft was saved. Limited per token.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    )]
    #[ToolAccess(scope: ApiTokenScope::MailDraft)]
    public function messageDraft(array $to, string $subject, string $body, array $cc = [], array $bcc = []): array
    {
        ToolTimeLimit::raiseTo(self::TIME_LIMIT_SECONDS);

        $user = $this->currentActor->get()->user;

        try {
            $mail = $this->composer->compose((string) $user, array_values($to), array_values($cc), array_values($bcc), $subject, $body);

            $this->auditLog->target('message', $mail->messageId);
            $this->auditLog->details(['recipients' => $mail->recipients, 'message_id' => $mail->messageId]);

            $mailbox = $this->drafter->save($user, (int) $this->currentApiToken->get()->getId(), $mail);
        } catch (InvalidMailException|MailDraftRateLimitedException|MailDraftFailedException $e) {
            throw new ToolCallException($e->getMessage());
        }

        $this->auditLog->details(['draft_folder' => $mailbox]);

        return [
            'drafted' => true,
            'message_id' => $mail->messageId,
            'from' => (string) $user,
            'recipients' => $mail->recipients,
            'mailbox' => $mailbox,
        ];
    }
}
