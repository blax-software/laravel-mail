<?php

declare(strict_types=1);

namespace Blax\Mail\Events;

use Blax\Mail\Models\MailMessage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fires the moment `MailDispatcher::dispatch()` persists the row and
 * enqueues `SendMailJob`. The mail has NOT been sent yet — listeners
 * watching for "actually delivered to SMTP" should use
 * `OutboundMailSent` instead.
 *
 * Useful for: optimistic UI updates, audit logging of dispatch intent,
 * setting per-row metadata before the queue worker picks the job up.
 */
class OutboundMailQueued
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly MailMessage $message,
    ) {
    }
}
