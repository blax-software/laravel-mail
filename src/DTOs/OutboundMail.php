<?php

declare(strict_types=1);

namespace Blax\Mail\DTOs;

use Blax\Mail\Models\Mailbox;

/**
 * Read-only payload describing one mail the host application wants to
 * send. Constructed by the host (the spedition's composer, a queued
 * notification, a quick `Mail::raw()`-style helper, whatever) and
 * handed to `MailDispatcher::dispatch()`.
 *
 * The DTO carries no Eloquent state — it's pure data. The dispatcher
 * is the only place that touches the database; consumers stay free of
 * the package's persistence concerns.
 *
 * Either `bodyHtml` or `bodyText` (or both) must be set. Plain-text
 * mode is supported because some transactional senders prefer it for
 * delivery rates.
 *
 * `replyTo` overrides the mailbox's default reply-to header for this
 * one send — useful for support flows where each ticket carries a
 * different reply address.
 *
 * `subjectType` / `subjectId` is the polymorphic escape hatch on the
 * package's `MailMessage` table. Apps with M:N business logic ignore
 * it; the value passes through to the persisted row + every emitted
 * event so listeners can find their own pivot rows.
 */
final class OutboundMail
{
    /**
     * @param  Mailbox  $mailbox     Identity to send from. Must have `canSend() === true`.
     * @param  array<int, string>  $to    Primary recipients.
     * @param  array<int, string>  $cc    CC recipients (visible).
     * @param  array<int, string>  $bcc   BCC recipients (hidden).
     * @param  array<int, OutboundAttachment>  $attachments  Files to attach.
     * @param  array<string, string>  $headers  Extra headers (e.g. `X-Campaign-Id`).
     * @param  array<string, mixed>  $meta  Free-form metadata persisted on the row.
     */
    public function __construct(
        public readonly Mailbox $mailbox,
        public readonly array $to,
        public readonly string $subject,
        public readonly ?string $bodyHtml = null,
        public readonly ?string $bodyText = null,
        public readonly array $cc = [],
        public readonly array $bcc = [],
        public readonly ?string $replyTo = null,
        public readonly ?string $inReplyTo = null,
        public readonly array $attachments = [],
        public readonly array $headers = [],
        public readonly ?string $subjectType = null,
        public readonly ?string $subjectId = null,
        public readonly array $meta = [],
    ) {
        if (! $bodyHtml && ! $bodyText) {
            throw new \InvalidArgumentException(
                'OutboundMail needs either bodyHtml or bodyText (or both).'
            );
        }
        if (empty($to)) {
            throw new \InvalidArgumentException('OutboundMail needs at least one `to` address.');
        }
    }
}
