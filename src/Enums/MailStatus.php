<?php

declare(strict_types=1);

namespace Blax\Mail\Enums;

/**
 * Lifecycle state of a `MailMessage` row.
 *
 * Outbound lifecycle (`MailDispatcher`):
 *
 *   Queued → Sending → Sent → Delivered  (happy path)
 *                          ↘ Bounced     (provider rejection)
 *                          ↘ Failed      (transport error / retries exhausted)
 *
 * Inbound lifecycle (`ImapPoller`):
 *
 *   Received → Read       (host app marks read via the command bus)
 *
 * The shared `Failed` value covers any terminal error condition where
 * the message exists in our table but never reached its mailbox. Use
 * `last_error` on the `MailMessage` for the human-readable reason.
 *
 * Statuses are checked by code (gates, filters in queries) so the enum
 * is the single source of truth — never compare against the raw string
 * outside this file.
 */
enum MailStatus: string
{
    // Outbound
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Bounced = 'bounced';
    case Failed = 'failed';

    // Inbound
    case Received = 'received';
    case Read = 'read';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Delivered, self::Bounced, self::Failed, self::Read => true,
            default => false,
        };
    }

    public function isOutbound(): bool
    {
        return match ($this) {
            self::Queued, self::Sending, self::Sent, self::Delivered,
            self::Bounced, self::Failed => true,
            default => false,
        };
    }

    public function isInbound(): bool
    {
        return match ($this) {
            self::Received, self::Read => true,
            default => false,
        };
    }
}
