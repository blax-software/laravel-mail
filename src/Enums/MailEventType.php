<?php

declare(strict_types=1);

namespace Blax\Mail\Enums;

/**
 * Event types persisted in the `mail_events` table.
 *
 * Each `MailEvent` row is an append-only fact about a `MailMessage` or
 * one of its recipients. Use the event log (rather than mutating
 * `MailMessage.status`) when you need an audit trail — which provider
 * marked the bounce, which user agent opened the pixel, etc.
 *
 * Event types split by source:
 *
 *  - Dispatcher-emitted: Sent, Queued, Failed
 *  - Transport-emitted (Symfony Mailer events): Delivered, Bounced,
 *    Complaint
 *  - Tracking-endpoint-emitted: Opened, Clicked
 *  - Poller-emitted: Received, Threaded
 */
enum MailEventType: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Bounced = 'bounced';
    case Complaint = 'complaint';
    case Failed = 'failed';
    case Opened = 'opened';
    case Clicked = 'clicked';
    case Received = 'received';
    case Threaded = 'threaded';
}
