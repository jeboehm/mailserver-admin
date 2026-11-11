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
use App\Exception\MailSend\SenderNotAllowedException;
use App\Mcp\Audit\ToolAuditLog;
use App\Mcp\CurrentApiToken;
use App\Mcp\ToolAccess;
use App\Mcp\ToolTimeLimit;
use App\Service\ApiToken\ApiTokenScope;
use App\Service\MailDraft\MailDrafter;
use App\Service\MailSend\MailComposer;
use App\Service\MailSend\SenderResolver;
use App\Service\Security\Access\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Save mail as a draft for the authenticated user, and only that: nothing is sent, the sender mode of
 * the token decides the sender (see SenderResolver) and no tool has a parameter for a mailbox. A
 * person sends the draft.
 */
final readonly class MailDraftTools
{
    /**
     * Above PHP's default of 30: the IMAP login can wait for an auth penalty of up to 15 seconds.
     */
    private const int TIME_LIMIT_SECONDS = 60;

    public function __construct(
        private MailComposer $composer,
        private SenderResolver $senderResolver,
        private MailDrafter $drafter,
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
        name: 'message_draft',
        description: 'Saves a plain text mail from your own address, as "send_as" in whoami shows, as a draft in your Drafts mailbox. The mail is NOT sent: a person has to open the draft in their mail client, check it and send it themselves. Every call creates a new draft. If "drafted" is true in the result the draft exists, so do not call this again for the same mail. A failure is an error and no draft was saved. Limited per token.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    )]
    #[ToolAccess(scope: ApiTokenScope::MailDraft)]
    public function messageDraft(array $to, string $subject, string $body, array $cc = [], array $bcc = [], ?string $from_address = null): array
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

            $mailbox = $this->drafter->save($user, (int) $token->getId(), $mail);
        } catch (SenderNotAllowedException|InvalidMailException|MailDraftRateLimitedException|MailDraftFailedException $e) {
            throw new ToolCallException($e->getMessage());
        }

        $this->auditLog->details(['draft_folder' => $mailbox]);

        return [
            'drafted' => true,
            'message_id' => $mail->messageId,
            'from' => $from,
            'recipients' => $mail->recipients,
            'mailbox' => $mailbox,
        ];
    }
}
