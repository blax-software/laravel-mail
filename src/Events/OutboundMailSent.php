<?php

declare(strict_types=1);

namespace Blax\Mail\Events;

use Blax\Mail\Models\MailMessage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fires after the SMTP transport accepted the mail. At this point the
 * row's `status` is `Sent` and `sent_at` is set, but the recipient's
 * server may still bounce later — listeners that care about hard
 * delivery confirmation should listen to bounce/complaint events
 * provided by their transport (or webhook integration).
 */
class OutboundMailSent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly MailMessage $message,
    ) {
    }
}
