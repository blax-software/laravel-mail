<?php

declare(strict_types=1);

namespace Blax\Mail\Services;

use Blax\Mail\Contracts\Dispatcher;
use Blax\Mail\DTOs\OutboundAttachment;
use Blax\Mail\DTOs\OutboundMail;
use Blax\Mail\Enums\MailDirection;
use Blax\Mail\Enums\MailEventType;
use Blax\Mail\Enums\MailStatus;
use Blax\Mail\Enums\RecipientKind;
use Blax\Mail\Events\OutboundMailQueued;
use Blax\Mail\Jobs\SendMailJob;
use Blax\Mail\Models\MailEvent;
use Blax\Mail\Models\MailMessage;
use Blax\Mail\Models\MailRecipient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Default `Dispatcher` implementation.
 *
 * Flow on `dispatch()`:
 *
 *   1. Generate a Message-ID + tracking token.
 *   2. Inject the tracking pixel into `bodyHtml`.
 *   3. Persist a `MailMessage` row (status `Queued`) inside a
 *      transaction along with one `MailRecipient` per address.
 *   4. Log a `MailEvent::Queued`.
 *   5. Record one `MailAttachment` row per `OutboundAttachment`
 *      (metadata only — see `recordAttachments()`), unless
 *      `outbound.record_attachments` is off.
 *   6. Fire `OutboundMailQueued`.
 *   7. Enqueue `SendMailJob` so the actual SMTP send happens off the
 *      request thread.
 *
 * The job is responsible for status transitions (`Sending` → `Sent` →
 * `Delivered`/`Failed`) and for firing `OutboundMailSent` /
 * `OutboundMailFailed`. This split keeps `dispatch()` fast (returns
 * the persisted row immediately) and lets the caller subscribe to
 * lifecycle events instead of polling.
 *
 * The dispatcher is not bound to any particular SMTP transport — the
 * job uses Laravel's `Mail::mailer(...)` machinery with a per-mailbox
 * runtime config so apps can keep one Mailbox row per provider and
 * the Laravel mail config stays empty (or only carries the default
 * mailer for non-tracked sends like password resets).
 */
class MailDispatcher implements Dispatcher
{
    public function __construct(
        protected MailTracker $tracker,
    ) {}

    public function dispatch(OutboundMail $mail): MailMessage
    {
        $mailbox = $mail->mailbox;
        if (! $mailbox->canSend()) {
            throw new \RuntimeException(
                "Mailbox '{$mailbox->name}' is not configured for outbound (canSend === false)."
            );
        }

        $token = $this->tracker->generateToken();
        $messageId = $this->generateMessageId($mailbox->email);
        $bodyHtml = $mail->bodyHtml
            ? $this->tracker->injectPixel($mail->bodyHtml, $token)
            : null;

        $message = DB::transaction(function () use ($mail, $mailbox, $messageId, $token, $bodyHtml) {
            $row = MailMessage::create([
                'mailbox_id' => $mailbox->id,
                'direction' => MailDirection::Outbound,
                'status' => MailStatus::Queued,
                'message_id' => $messageId,
                'in_reply_to' => $mail->inReplyTo,
                'subject' => $mail->subject,
                'body_text' => $mail->bodyText,
                'body_html' => $bodyHtml,
                'from_address' => $mailbox->email,
                'from_name' => $mailbox->from_name,
                // Denormalized snapshot — the source of truth lives on
                // the `mail_recipients` rows we create below, but
                // having the addresses on the row makes list views
                // cheap (no join just to render "To: …").
                'to' => $mail->to,
                'cc' => $mail->cc,
                'bcc' => $mail->bcc,
                'queued_at' => now(),
                'tracking_token' => $token,
                'subject_type' => $mail->subjectType,
                'subject_id' => $mail->subjectId,
                'meta' => array_merge($mail->meta, [
                    'reply_to' => $mail->replyTo,
                    'headers' => $mail->headers,
                ]),
            ]);

            $this->persistRecipients($row, $mail);

            MailEvent::record($row->id, MailEventType::Queued, meta: [
                'recipient_count' => count($mail->to) + count($mail->cc) + count($mail->bcc),
            ]);

            return $row;
        });

        if ((bool) config('blax-mail.outbound.record_attachments', true)) {
            $this->recordAttachments($message, $mail);
        }

        OutboundMailQueued::dispatch($message);

        // Hand off to the queue. The job receives the persisted id +
        // the serialized DTO so it doesn't have to rebuild the
        // attachment list out of database rows.
        SendMailJob::dispatch($message->id, $mail);

        return $message;
    }

    /**
     * RFC 5322 Message-ID: `<unique@domain>`. The domain comes from
     * the mailbox's `email` host part so receiving servers can verify
     * the sender's DKIM/SPF without flagging the Message-ID as
     * mismatched.
     */
    protected function generateMessageId(string $mailboxEmail): string
    {
        $domain = Str::after($mailboxEmail, '@') ?: parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
        $unique = bin2hex(random_bytes(16));

        return '<'.$unique.'@'.$domain.'>';
    }

    /**
     * One `mail_attachments` row per `OutboundAttachment` so the read
     * side (`with('attachments')`, `GetThreadQuery`) shows what went
     * out. Bookkeeping only — the bytes still travel inside the queued
     * `SendMailJob`, and `storage_disk` / `storage_path` stay null on
     * purpose: the file belongs to whoever built the DTO (a generated
     * invoice PDF, a laravel-files record, a logo on disk) and
     * `blax-mail:cleanup` deletes whatever those two columns point at.
     *
     * Runs after the message transaction committed and never throws:
     * a row we cannot record is logged and skipped so the mail is
     * queued exactly as it was before this bookkeeping existed.
     */
    protected function recordAttachments(MailMessage $row, OutboundMail $mail): void
    {
        foreach ($mail->attachments as $attachment) {
            try {
                $this->recordAttachment($row, $attachment);
            } catch (\Throwable $e) {
                Log::warning("Mail {$row->getKey()}: could not record attachment '{$attachment->filename}': {$e->getMessage()}");
            }
        }
    }

    protected function recordAttachment(MailMessage $row, OutboundAttachment $attachment): void
    {
        $bytes = $attachment->bytes;
        $path = $attachment->path;
        $onDisk = $path !== null && is_file($path) && is_readable($path);

        $row->attachments()->create([
            'filename' => $attachment->filename,
            'mime_type' => $attachment->mimeType,
            'size_bytes' => $bytes !== null ? strlen($bytes) : ($onDisk ? (int) filesize($path) : 0),
            'storage_disk' => null,
            'storage_path' => null,
            'content_id' => $attachment->contentId,
            'inline' => $attachment->isInline(),
            'checksum' => $bytes !== null ? hash('sha256', $bytes) : ($onDisk ? hash_file('sha256', $path) : null),
            'meta' => array_filter(['source' => 'outbound', 'path' => $path]),
        ]);
    }

    protected function persistRecipients(MailMessage $row, OutboundMail $mail): void
    {
        $rows = [];
        foreach ($mail->to as $address) {
            $rows[] = [RecipientKind::To, $address];
        }
        foreach ($mail->cc as $address) {
            $rows[] = [RecipientKind::Cc, $address];
        }
        foreach ($mail->bcc as $address) {
            $rows[] = [RecipientKind::Bcc, $address];
        }

        foreach ($rows as [$kind, $address]) {
            $normalized = MailRecipient::normalizeAddress($address);
            if ($normalized === '') {
                continue;
            }
            $row->recipients()->create([
                'kind' => $kind,
                'address' => $normalized,
                'meta' => $normalized === trim($address) ? null : ['raw' => $address],
            ]);
        }
    }
}
